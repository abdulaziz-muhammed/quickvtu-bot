<?php
define('BOT_TOKEN', '8824991415:AAGmb4jkviHCmBoGJIDnHiDGR5UI7Q-lfQA');
define('GROQ_KEY', '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');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

require_once 'config.php';

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$message = $update['message'] ?? null;
if (!$message) exit;

$chatId = $message['chat']['id'];
$firstName = $message['chat']['first_name'] ?? 'Customer';

function sendMessage($chatId, $text) {
    $url = API_URL . 'sendMessage';
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
}

function getFileUrl($fileId) {
    $url = API_URL . 'getFile?file_id=' . $fileId;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    $filePath = $data['result']['file_path'] ?? null;
    if (!$filePath) return null;
    return 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . $filePath;
}

function transcribeVoice($fileUrl) {
    $audioData = file_get_contents($fileUrl);
    $tmpFile = tempnam(sys_get_temp_dir(), 'voice_') . '.ogg';
    file_put_contents($tmpFile, $audioData);
    $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'file' => new CURLFile($tmpFile, 'audio/ogg', 'voice.ogg'),
        'model' => 'whisper-large-v3',
        'language' => 'en'
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . GROQ_KEY]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    unlink($tmpFile);
    $data = json_decode($res, true);
    return $data['text'] ?? null;
}

function askAI($userText, $sessionData) {
    $plans = "- 110MB Daily: N97 (ID: 100.01)\n- 230MB Daily: N194 (ID: 200.01)\n- 500MB Weekly: N485 (ID: 500.02)\n- 1GB Weekly: N776 (ID: 800.01)";
    $systemPrompt = "You are QuickVTU bot, a friendly Nigerian assistant on Telegram that helps people buy MTN data.\nAvailable plans:\n$plans\n\nSteps:\n1. Greet and ask what plan they want\n2. Ask for their phone number\n3. NEVER use placeholder numbers\n4. Confirm number and ask YES to proceed\n5. After YES reply with ACTION:{\"buy\":true,\"plan\":\"PLANID\",\"phone\":\"NUMBER\"}\n\nKeep replies short and friendly.";
    $messages = [["role" => "system", "content" => $systemPrompt]];
    $history = $sessionData['history'] ?? [];
    foreach (array_slice($history, -10) as $h) { $messages[] = $h; }
    $messages[] = ["role" => "user", "content" => $userText];
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["model" => "llama-3.1-8b-instant", "messages" => $messages, "max_tokens" => 200, "temperature" => 0.7]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . GROQ_KEY, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data['choices'][0]['message']['content'] ?? "Sorry, try again.";
}

function buyData($planId, $phone) {
    $plans = ["100.01" => ["amount" => 97, "desc" => "MTN 110MB Daily"], "200.01" => ["amount" => 194, "desc" => "MTN 230MB Daily"], "500.02" => ["amount" => 485, "desc" => "MTN 500MB Weekly"], "800.01" => ["amount" => 776, "desc" => "MTN 1GB Weekly"]];
    if (!isset($plans[$planId])) return ["success" => false, "msg" => "Invalid plan."];
    $amount = $plans[$planId]['amount'];
    $desc = $plans[$planId]['desc'];
    $apiURL = "https://www.nellobytesystems.com/APIDatabundleV1.asp?UserID=CK101281393&APIKey=A169TO8GU2R27Q2U1T3W944PFKTD636Q9429J3ZVYB3TNT705IPW02904A28WAVP&MobileNetwork=01&DataPlan=" . $planId . "&MobileNumber=" . $phone . "&RequestID=" . time();
    $ch = curl_init($apiURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($response, true);
    if (isset($decoded['status']) && $decoded['status'] === 'ORDER_RECEIVED') {
        return ["success" => true, "msg" => "✅ Done! $desc sent to $phone."];
    }
    return ["success" => false, "msg" => "❌ Failed: " . ($decoded['status'] ?? $response)];
}

$sessionRow = $db->query("SELECT * FROM tg_sessions WHERE chat_id='$chatId'")->fetch_assoc();
$sessionData = $sessionRow ? json_decode($sessionRow['data'], true) : ['history' => []];

$userText = null;
if (isset($message['text'])) {
    $userText = $message['text'];
} elseif (isset($message['voice'])) {
    sendMessage($chatId, "🎙️ Got your voice note! Transcribing...");
    $fileUrl = getFileUrl($message['voice']['file_id']);
    if ($fileUrl) {
        $userText = transcribeVoice($fileUrl);
        if ($userText) {
            sendMessage($chatId, "📝 You said: \"$userText\"");
        } else {
            sendMessage($chatId, "❌ Could not transcribe. Please type your message.");
            exit;
        }
    }
}

if (!$userText) exit;

if ($userText === '/start') {
    $sessionData = ['history' => []];
    sendMessage($chatId, "👋 Welcome to QuickVTU, $firstName!\n\nI help you buy MTN data. Just tell me what you need or send a voice note!\n\n📶 110MB Daily - ₦97\n📶 230MB Daily - ₦194\n📶 500MB Weekly - ₦485\n📶 1GB Weekly - ₦776");
} else {
    $aiReply = askAI($userText, $sessionData);
    $sessionData['history'][] = ["role" => "user", "content" => $userText];
    $sessionData['history'][] = ["role" => "assistant", "content" => $aiReply];
    if (preg_match('/ACTION:\{[^}]+\}/', $aiReply, $matches)) {
        $action = json_decode(str_replace('ACTION:', '', $matches[0]), true);
        if ($action && $action['buy'] && $action['plan'] && $action['phone']) {
            $cleanReply = trim(preg_replace('/ACTION:\{[^}]+\}/', '', $aiReply));
            sendMessage($chatId, $cleanReply ?: "Processing your order...");
            $result = buyData($action['plan'], $action['phone']);
            sendMessage($chatId, $result['msg']);
            if ($result['success']) $sessionData = ['history' => []];
        }
    } else {
        sendMessage($chatId, trim(preg_replace('/ACTION:\{[^}]+\}/', '', $aiReply)));
    }
}

$sessionJson = $db->real_escape_string(json_encode($sessionData));
if ($sessionRow) {
    $db->query("UPDATE tg_sessions SET data='$sessionJson' WHERE chat_id='$chatId'");
} else {
    $db->query("INSERT INTO tg_sessions (chat_id, data) VALUES ('$chatId', '$sessionJson')");
}
?>
