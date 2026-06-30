<?php
define('BOT_TOKEN', '8824991415:AAGmb4jkviHCmBoGJIDnHiDGR5UI7Q-lfQA');
define('GROQ_KEY', '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');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;
$message = $update['message'] ?? null;
if (!$message) exit;

$chatId = $message['chat']['id'];
$firstName = $message['chat']['first_name'] ?? 'Customer';

function sendMessage($chatId, $text) {
    $ch = curl_init(API_URL . 'sendMessage');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $chatId, 'text' => $text]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch); curl_close($ch);
}

function getFileUrl($fileId) {
    $ch = curl_init(API_URL . 'getFile?file_id=' . $fileId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch); curl_close($ch);
    $data = json_decode($res, true);
    $fp = $data['result']['file_path'] ?? null;
    return $fp ? 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . $fp : null;
}

function transcribeVoice($fileUrl) {
    $audioData = file_get_contents($fileUrl);
    $tmpFile = tempnam(sys_get_temp_dir(), 'voice_') . '.ogg';
    file_put_contents($tmpFile, $audioData);
    $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($tmpFile, 'audio/ogg', 'voice.ogg'), 'model' => 'whisper-large-v3', 'language' => 'en']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . GROQ_KEY]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch); curl_close($ch);
    unlink($tmpFile);
    $data = json_decode($res, true);
    return $data['text'] ?? null;
}

function textToSpeech($text, $chatId = null) {
    $clean = trim(preg_replace('/ACTION:\{[^}]+\}/', '', $text));
    if (!$clean) return null;
    if (strlen($clean) > 195) $clean = substr($clean, 0, 195);
    $ch = curl_init('https://api.groq.com/openai/v1/audio/speech');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["model" => "canopylabs/orpheus-v1-english", "input" => $clean, "voice" => "troy", "response_format" => "wav"]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . GROQ_KEY, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $audio = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 && $chatId) {
        sendMessage($chatId, "TTS DEBUG: HTTP $httpCode - " . substr($audio, 0, 300));
    }
    return $audio;
}

function sendVoiceNote($chatId, $audioData) {
    if (!$audioData) return;
    $tmpFile = tempnam(sys_get_temp_dir(), 'tts_') . '.wav';
    file_put_contents($tmpFile, $audioData);
    $ch = curl_init(API_URL . 'sendAudio');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $chatId, 'audio' => new CURLFile($tmpFile, 'audio/wav', 'reply.wav')]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
    unlink($tmpFile);
}

function askAI($userText, $history) {
    $plans = "- 110MB Daily: N97 (ID: 100.01)\n- 230MB Daily: N194 (ID: 200.01)\n- 500MB Weekly: N485 (ID: 500.02)\n- 1GB Weekly: N776 (ID: 800.01)";
    $systemPrompt = "You are QuickVTU bot, a friendly Nigerian assistant on Telegram that helps people buy MTN data.\nPlans:\n$plans\nSteps:\n1. Ask what plan they want\n2. Ask for their phone number\n3. NEVER use placeholder numbers\n4. Confirm number, ask YES\n5. After YES reply with ACTION:{\"buy\":true,\"plan\":\"PLANID\",\"phone\":\"NUMBER\"}\nKeep replies SHORT - max 150 characters.";
    $messages = [["role" => "system", "content" => $systemPrompt]];
    foreach (array_slice($history, -10) as $h) { $messages[] = $h; }
    $messages[] = ["role" => "user", "content" => $userText];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["model" => "llama-3.1-8b-instant", "messages" => $messages, "max_tokens" => 150, "temperature" => 0.7]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . GROQ_KEY, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch); curl_close($ch);
    $data = json_decode($res, true);
    return $data['choices'][0]['message']['content'] ?? "Sorry, try again.";
}

function buyData($planId, $phone) {
    $plans = ["100.01" => ["amount" => 97, "desc" => "MTN 110MB Daily"], "200.01" => ["amount" => 194, "desc" => "MTN 230MB Daily"], "500.02" => ["amount" => 485, "desc" => "MTN 500MB Weekly"], "800.01" => ["amount" => 776, "desc" => "MTN 1GB Weekly"]];
    if (!isset($plans[$planId])) return ["success" => false, "msg" => "Invalid plan."];
    $desc = $plans[$planId]['desc'];
    $apiURL = "https://www.nellobytesystems.com/APIDatabundleV1.asp?UserID=CK101281393&APIKey=A169TO8GU2R27Q2U1T3W944PFKTD636Q9429J3ZVYB3TNT705IPW02904A28WAVP&MobileNetwork=01&DataPlan=" . $planId . "&MobileNumber=" . $phone . "&RequestID=" . time();
    $ch = curl_init($apiURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch); curl_close($ch);
    $decoded = json_decode($response, true);
    if (isset($decoded['status']) && $decoded['status'] === 'ORDER_RECEIVED') {
        return ["success" => true, "msg" => "Done! $desc sent to $phone."];
    }
    return ["success" => false, "msg" => "Failed: " . ($decoded['status'] ?? 'unknown error')];
}

$sessionFile = sys_get_temp_dir() . '/tg_' . $chatId . '.json';
$sessionData = file_exists($sessionFile) ? json_decode(file_get_contents($sessionFile), true) : ['history' => []];

$userText = null;
if (isset($message['text'])) {
    $userText = $message['text'];
} elseif (isset($message['voice'])) {
    sendMessage($chatId, "🎙️ Transcribing...");
    $fileUrl = getFileUrl($message['voice']['file_id']);
    if ($fileUrl) {
        $userText = transcribeVoice($fileUrl);
        if ($userText) { sendMessage($chatId, "📝 You said: \"$userText\""); }
        else { sendMessage($chatId, "❌ Could not transcribe."); exit; }
    }
}
if (!$userText) exit;

if ($userText === '/start') {
    $sessionData = ['history' => []];
    $welcome = "Welcome to QuickVTU, $firstName! 110MB Daily is 97 naira, 230MB Daily is 194 naira, 500MB Weekly is 485 naira, 1GB Weekly is 776 naira. What would you like to buy?";
    sendMessage($chatId, "👋 $welcome");
    sendVoiceNote($chatId, textToSpeech($welcome, $chatId));
} else {
    $aiReply = askAI($userText, $sessionData['history']);
    $sessionData['history'][] = ["role" => "user", "content" => $userText];
    $sessionData['history'][] = ["role" => "assistant", "content" => $aiReply];
    if (preg_match('/ACTION:\{[^}]+\}/', $aiReply, $matches)) {
        $action = json_decode(str_replace('ACTION:', '', $matches[0]), true);
        if ($action && $action['buy'] && $action['plan'] && $action['phone']) {
            $clean = trim(preg_replace('/ACTION:\{[^}]+\}/', '', $aiReply));
            if ($clean) { sendMessage($chatId, $clean); sendVoiceNote($chatId, textToSpeech($clean, $chatId)); }
            $result = buyData($action['plan'], $action['phone']);
            sendMessage($chatId, $result['msg']);
            sendVoiceNote($chatId, textToSpeech($result['msg'], $chatId));
            if ($result['success']) $sessionData = ['history' => []];
        }
    } else {
        $clean = trim(preg_replace('/ACTION:\{[^}]+\}/', '', $aiReply));
        sendMessage($chatId, $clean);
        sendVoiceNote($chatId, textToSpeech($clean, $chatId));
    }
}
file_put_contents($sessionFile, json_encode($sessionData));
?>
