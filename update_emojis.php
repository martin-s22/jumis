<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$clickup_api_key = 'pk_164563706_VIM4ZPFTGVB92NNNXSA0PJDAW1IOBLQ2';
$statusEmojis = [
    'незавршен' => '⭕',
    'во изработка' => '🔵',
    'комплетиран' => '🟢',
];
$statusColors = [
    'незавршен' => '#c62a2f',
    'во изработка' => '#0880ea',
    'комплетиран' => '#299764',
];
function updateClickUpFolderEmoji($folder_id, $emoji, $api_key) {
    $folder_url = "https://api.clickup.com/api/v2/folder/{$folder_id}";
    $ch = curl_init($folder_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: {$api_key}", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => $emoji . ' ' . substr(getFolderName($folder_id, $api_key), 2)])); //remove old emoji, add new
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        error_log("cURL Error updating folder emoji: " . $curlError . " for Folder ID: " . $folder_id);
        return false;
    }
    if ($httpCode != 200) {
        error_log("HTTP Error updating folder emoji ($httpCode): " . $response . " for Folder ID: " . $folder_id);
        return false;
    }
    return true;
}
function updateClickUpListEmoji($list_id, $emoji, $api_key) {
    $list_url = "https://api.clickup.com/api/v2/list/{$list_id}";
    $ch = curl_init($list_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: {$api_key}", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => $emoji . ' ' . substr(getListName($list_id, $api_key), 2)])); //remove old emoji, add new
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        error_log("cURL Error updating list emoji: " . $curlError . " for List ID: " . $list_id);
        return false;
    }
    if ($httpCode != 200) {
        error_log("HTTP Error updating list emoji ($httpCode): " . $response . " for List ID: " . $list_id);
        return false;
    }
    return true;
}
function getFolderName($folder_id, $api_key){
    $url = "https://api.clickup.com/api/v2/folder/{$folder_id}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: {$api_key}", "Content-Type: application/json"]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError || $httpCode != 200) {
        return "";
    }
    $decodedResponse = json_decode($response, true);
    return $decodedResponse['name'];
}
function getListName($list_id, $api_key){
    $url = "https://api.clickup.com/api/v2/list/{$list_id}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: {$api_key}", "Content-Type: application/json"]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError || $httpCode != 200) {
        return "";
    }
    $decodedResponse = json_decode($response, true);
    return $decodedResponse['name'];
}
function updateFolderListEmojiBasedOnTasks($folder_id, $list_id, $clickup_api_key) {
    global $statusEmojis;
    $isFolder = ($list_id === null);
    $targetId = $isFolder ? $folder_id : $list_id;
    $url = $isFolder ? "https://api.clickup.com/api/v2/folder/{$targetId}/task" : "https://api.clickup.com/api/v2/list/{$targetId}/task";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: {$clickup_api_key}", "Content-Type: application/json"]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError || $httpCode != 200) {
        error_log("Error fetching tasks for " . ($isFolder ? "folder" : "list") . " {$targetId}: " . ($curlError ?: $response));
        return false;
    }
    $tasks = json_decode($response, true)['tasks'] ?? [];
    $allCompleted = true;
    $anyInProgress = false;
    foreach ($tasks as $task) {
        if ($task['status']['status'] !== 'комплетиран') {
            $allCompleted = false;
        }
        if ($task['status']['status'] === 'во изработка') {
            $anyInProgress = true;
        }
    }
    $emoji = $statusEmojis['незавршен']; // Default to unstarted
    if ($allCompleted && count($tasks) > 0) {
        $emoji = $statusEmojis['комплетиран'];
    } elseif ($anyInProgress) {
        $emoji = $statusEmojis['во изработка'];
    }
    if($isFolder){
        return updateClickUpFolderEmoji($folder_id, $emoji, $clickup_api_key);
    } else {
        return updateClickUpListEmoji($list_id, $emoji, $clickup_api_key);
    }
}
function getClickUpTaskDetails($task_id, $api_key) {
    $url = "https://api.clickup.com/api/v2/task/{$task_id}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: {$api_key}", "Content-Type: application/json"]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError || $httpCode != 200) {
        error_log("Error fetching task details: " . ($curlError ?: $response));
        return null;
    }
    return json_decode($response, true);
}
$payload = json_decode(file_get_contents('php://input'), true);
if (isset($payload['task_id'])) {
    $task_id = $payload['task_id'];
    $task_details = getClickUpTaskDetails($task_id, $clickup_api_key);
    if ($task_details) {
        $list_id = $task_details['list']['id'];
        $folder_id = $task_details['folder']['id'];
        updateFolderListEmojiBasedOnTasks($folder_id, $list_id, $clickup_api_key);
    }
}
?>