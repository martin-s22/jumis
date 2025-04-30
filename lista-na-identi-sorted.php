<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (isset($_POST['selected_offers']) && !empty($_POST['selected_offers'])) {
    try {
        error_log("Raw selected_offers: " . $_POST['selected_offers']);
        $selectedOffers = json_decode($_POST['selected_offers'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decoding error: " . json_last_error_msg());
            echo json_encode(['error' => 'JSON decoding error: ' . json_last_error_msg()]);
            exit;
        }
        error_log("Decoded selectedOffers: " . print_r($selectedOffers, true));

        // Check if localStorage orders were sent
        if (isset($_POST['local_storage_orders'])) {
            $localStorageOrders = json_decode($_POST['local_storage_orders'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decoding error for local_storage_orders: " . json_last_error_msg());
                echo json_encode(['error' => 'JSON decoding error for local storage orders: ' . json_last_error_msg()]);
                exit;
            }
            error_log("Decoded localStorageOrders: " . print_r($localStorageOrders, true));
            $dataData = ['orders' => $localStorageOrders]; // Use localStorage orders
        } else {
            // Fallback to reading from retrieved-data.json if localStorage data is not sent
            $dataData = json_decode(file_get_contents('retrieved-data.json'), true);
            if (!isset($dataData['orders'])) {
                $dataData['orders'] = [];
            }
            error_log("Using orders from retrieved-data.json");
        }
        $setItemMap = [];
        if (!empty($setItemData)) {
            foreach ($setItemData as $setItem) {
                if (isset($setItem['acIdent']) && isset($setItem['ACCLASSIF'])) {
                    $setItemMap[$setItem['acIdent']] = $setItem['ACCLASSIF'];
                }
            }
        }
        $clickUpResults = [];
        foreach ($dataData['orders'] as $order) {
            if (!isset($order['Orderitem']) || !is_array($order['Orderitem'])) {
                continue;
            }
            $folder_name = $order['acKeyView'] . ' ' . $order['acReceiver'];
            $folder_data = createClickUpFolder($clickup_space_id, $folder_name, $clickup_api_key);
            if (isset($folder_data['id'])) {
                $folder_id = $folder_data['id'];
                error_log("Folder created: " . $folder_id);
                $folder_info = [
                    'id' => $folder_id,
                    'name' => $folder_name,
                    'lists' => [],
                ];
                foreach ($order['Orderitem'] as $orderItem) {
                    $acDept = $orderItem['acDept'];
                    $acDeptClean = str_replace("Производство ", "", $acDept);
                    $list_name = $orderItem['acIdent'] . ' ' . $orderItem['acName'];
                    $list_data = createClickUpList($folder_id, $list_name, $clickup_api_key);
                    if (isset($list_data['id'])) {
                        $list_id = $list_data['id'];
                        error_log("ClickUp: List created: " . $list_id);
                        $list_info = [
                            'id' => $list_id,
                            'name' => $list_name,
                        ];
                        $folder_info['lists'][$list_id] = $list_info; // Add list info to the folder
                        $due_date = convertDateToTimestamp($order['adDeliveryDate']);
                        $start_date = convertDateToTimestamp($order['adDate']);
                        $task_data = [
                            'name' => "{$acDeptClean} {$orderItem['acIdent']} {$orderItem['acName']}",
                            'description' => $order['acNote'],
                            'tags' => ["{$orderItem['anQty']}"],
                            'status' => 'незавршен',
                            'due_date' => $due_date,
                            'due_date_time' => false,
                            'time_estimate' => 0,
                            'start_date' => $start_date,
                            'start_date_time' => false,
                            'notify_all' => false,
                            'parent' => null,
                            'links_to' => null,
                        ];
                        if (isset($assignees_mapping[$acDept][0])) {
                            $task_data['assignees'] = $assignees_mapping[$acDept];
                            $user_id = $assignees_mapping[$acDept][0];
                            error_log("ClickUp: Assignee found for department " . $acDept . ": User ID " . $user_id);
                        } else {
                            error_log("ClickUp: No assignees mapping found for department: " . $acDept);
                            continue;
                        }
                        $clickup_task_id = createClickUpTaskWithPhases($list_id, $task_data, $clickup_api_key);
                        if ($clickup_task_id) {
                            error_log("ClickUp: Task created with phases: " . $clickup_task_id);
                            // We are now storing folder and list info directly
                            // $clickUpResults[] = ['action' => 'created', 'task_id' => $clickup_task_id];
                        } else {
                            error_log("ClickUp: Task creation failed.");
                            // $clickUpResults[] = ['action' => 'failed'];
                        }
                    } else {
                        error_log("ClickUp: Error creating list: " . json_encode($list_data));
                        // $clickUpResults[] = ['action' => 'failed', 'error' => isset($list_data['error']) ? $list_data['error'] : 'Unknown error creating list'];
                    }
                }
                $clickUpResults[$folder_id] = $folder_info; // Store folder info using folder ID as key
            } else {
                error_log("ClickUp: Error creating folder: " . json_encode($folder_data));
                // $clickUpResults[] = ['action' => 'failed', 'error' => isset($folder_data['error']) ? $folder_data['error'] : 'Unknown error creating folder'];
            }
        }
        $offersFilePath = 'retrieved-offers.json';
        $existingOffers = [];
        if (file_exists($offersFilePath)) {
            $existingOffersData = json_decode(file_get_contents($offersFilePath), true);
            if (isset($existingOffersData['offers']) && is_array($existingOffersData['offers'])) {
                $existingOffers = $existingOffersData['offers'];
            }
        }
        $allOffers = array_merge($existingOffers,$offersData['offers']);
        file_put_contents('retrieved-offers.json', json_encode(['offers' => $offersData['offers']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents('retrieved-data.json', json_encode($dataData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        error_log("Error in selected_offers: " . $e->getMessage());
        echo json_encode(['error' => 'An error occurred. Check the server logs.']);
        exit;
    }
}
$authApiUrl = 'http://192.168.88.25/api/Users/authwithtoken';
$orderApiUrl = 'http://192.168.88.25/api/Order/retrieve';
$setItemApiUrl = 'http://192.168.88.25/api/Ident/retrieve';
$authPayload = json_encode([
    "username" => "MS",
    "password" => "12345678",
    "companyDB" => "PB_MJD"
]);
function sendCurlRequest($url, $token = null, $payload = null, $customRequest = "POST") {
    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        // CURLOPT_POST => true,
        CURLOPT_CUSTOMREQUEST => $customRequest,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_FAILONERROR => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $requestInfo = curl_getinfo($ch);
    curl_close($ch);
    error_log("cURL Request: " . $customRequest . " " . $url);
    if ($payload) {
        error_log("cURL Payload: " . $payload);
    }
    error_log("cURL Response Code: " . $httpCode);
    error_log("cURL Response: " . $response);
    if ($curlError) {
        error_log("cURL Error: " . $curlError);
    }
    error_log("cURL Request Info: " . json_encode($requestInfo));
    if ($curlError) {
        return null;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decoding error: " . json_last_error_msg() . " for response: " . $response);
            return null;
        }
        return $decodedResponse;
    } else {
        return json_decode($response, true);
    }
}
$authData = sendCurlRequest($authApiUrl, null, $authPayload);
if (!isset($authData['token'])) {
    die("Error: Token not found in authentication response.");
}
$token = $authData['token'];
function buildOrderPayload($date = null, $startDate = null, $endDate = null, $orderCode = null) {
    $customConditions = [
        "condition" => "ORD.acDocType IN (@param1, @param2, @param3, @param4, @param5)",
        "params" => ["0110", "0130", "0160", "0250", "02700"]
    ];
    if ($date) { // Today's orders
        $customConditions["condition"] .= " AND ORD.adDate = @param6";
        $customConditions["params"][] = $date;
    } elseif ($startDate && $endDate) { // Period orders
        $startDateObj = DateTime::createFromFormat('d.m.Y', $startDate);
        $endDateObj = DateTime::createFromFormat('d.m.Y', $endDate);
        if ($startDateObj && $endDateObj) {
            $formattedStartDate = $startDateObj->format('Y-m-d');
            $formattedEndDate = $endDateObj->format('Y-m-d');
            $customConditions["condition"] .= " AND ORD.adDate >= @param6 AND ORD.adDate <= @param7";
            $customConditions["params"][] = $formattedStartDate;
            $customConditions["params"][] = $formattedEndDate;
        } else {
            error_log("Invalid date format received: startDate=$startDate, endDate=$endDate");
            return json_encode(['error' => 'Invalid date format']);
        }
    }
    if ($orderCode) {
        $customConditions["condition"] .= " AND ORD.acKeyView = @param6";
        $customConditions["params"][] = $orderCode;
    }
    return json_encode([
        "start" => 0,
        "length" => 0,
        "fieldsToReturn" => "ORD.acKeyView, ORD.acReceiver, ORD.adDate, ORD.adDateValid, ORD.acDelivery, ORD.acNote, ORD.acStatus, ORD.adDeliveryDate, ORD.acDocType, ORD.acConsignee",
        "tableFKs" => [
            [
                "table" => "tHE_OrderItem",
                "join" => "Orderitem.acKey = ORD.acKey",
                "alias" => "Orderitem",
                "fieldsToReturn" => "acIdent, acName, anQty, acDept"
            ],
            [
                "table" => "tHE_SetItem",
                "join" => "SetItem.acIdent = Orderitem.acIdent",
                "alias" => "SetItem",
                "fieldsToReturn" => "acIdent, ACCLASSIF"
            ]
        ],
        "customConditions" => $customConditions,
        "sortColumn" => "ORD.adDate",
        "sortOrder" => "asc",
        "WithSubSelects" => 1,
        "tempTables" => []
    ]);
}
$input = json_decode(file_get_contents('php://input'), true);
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'order_suggestions') {
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        $limit = 999999999;
        $orderPayload = json_encode([
            "start" => $offset,
            "length" => $limit,
            "fieldsToReturn" => "ORD.acKeyView, ORD.adDate, ORD.acReceiver",
            "customConditions" => [
                "condition" => "ORD.acDocType IN (@param1, @param2, @param3, @param4, @param5)",
                "params" => ["0110", "0130", "0160", "0250", "02700"]
            ],
            "sortColumn" => "ORD.adDate",
            "sortOrder" => "desc",
            "WithSubSelects" => 1,
            "tempTables" => []
        ]);
        $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);
        if ($orderData && is_array($orderData)) {
            $suggestions = array_map(function ($order) {
                return [
                    'acKeyView' => $order['acKeyView'],
                    'acReceiver' => $order['acReceiver']
                ];
            }, $orderData);
            echo json_encode(['suggestions' => $suggestions]);
            exit;
        } else {
            echo json_encode(['suggestions' => []]);
            exit;
        }
    }
}
if (isset($input['action'])) {
    if ($input['action'] === 'order_search') {
        $orderCode = $input['order_code'];
        $orderPayload = json_encode([
            "fieldsToReturn" => "*",
            "customConditions" => [
                "condition" => "ORD.acKeyView = @param1",
                "params" => [$orderCode]
            ],
            "WithSubSelects" => 1,
            "tempTables" => []
        ]);
        $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);

        if ($orderData && is_array($orderData)) {
            echo json_encode(['orders' => $orderData]);
        } else {
            echo json_encode(['error' => 'Нема резултати.']);
        }
        exit;
    } elseif ($input['action'] === 'import_offers') { // Handle import_offers
        $selectedOffers = $input['selected_offers'];
        $selectedOffers = array_map('trim', $selectedOffers);
        $allOrders = [];
        foreach ($selectedOffers as $offerKey) {
            $orderPayload = json_encode([
                "fieldsToReturn" => "*",
                "customConditions" => [
                    "condition" => "ORD.acKeyView = @param1",
                    "params" => [$offerKey]
                ],
                "WithSubSelects" => 1,
                "tempTables" => []
            ]);
            $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);
            if ($orderData && is_array($orderData)) {
                $allOrders = array_merge($allOrders, $orderData);
            } else {
                echo json_encode(['error' => "Грешка при преземање на нарачка: " . $offerKey]);
                exit;
            }
        }
        if (count($allOrders) > 0) {
            echo json_encode(['orders' => $allOrders]);
        } else {
            echo json_encode(['error' => "Нема пронајдени нарачки за импорт."]);
        }
        exit;
    }
}
if (isset($_POST['order_code'])) {
    $orderCode = $_POST['order_code'];
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $orderCode)) {
        http_response_code(400);
        echo json_encode(['error' => "Invalid order code format."]);
        exit;
    }
    $orderPayload = json_encode([
        "fieldsToReturn" => "*",
        "customConditions" => [
            "condition" => "ORD.acKeyView = @param1",
            "params" => [$orderCode]
        ],
        "WithSubSelects" => 1,
        "tempTables" => []
    ]);
    $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);
    if ($orderData && is_array($orderData)) {
        echo json_encode(['orders' => $orderData]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Нема резултати.']);
        exit;
    }
}
$date = isset($_POST['date']) ? $_POST['date'] : null;
$startDate = isset($_POST['start_date']) ? $_POST['start_date'] : null;
$endDate = isset($_POST['end_date']) ? $_POST['end_date'] : null;
$orderCode = isset($_POST['order_code']) ? $_POST['order_code'] : null;
if ($orderCode) {
    $orderPayload = buildOrderPayload(null, null, null, $orderCode);
} elseif ($date) {
    $orderPayload = buildOrderPayload($date);
} elseif ($startDate && $endDate) {
    $orderPayload = buildOrderPayload(null, $startDate, $endDate);
} else {
    $orderPayload = buildOrderPayload();
}
$orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);
file_put_contents('debug-api-response.json', json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if (empty($orderData) || (isset($orderData['error']) && !empty($orderData['error']))) {
    // http_response_code(500);
    header('Content-Type: application/json');
    if (empty($orderData)) {
        echo json_encode(['message' => 'Нема денешни нарачки. Обиди се повторно покасно.']);
        exit;
    } else {
        $errorMessage = "Error from API: " . $orderData['error'];
        echo json_encode(['error' => $errorMessage]);
        exit;
    }
    // exit;
}
$saveJson = function ($data, $filePath) {
    $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json_data === false) {
    $error_message = json_last_error_msg();
    error_log("JSON encoding error: " . $error_message);
    die("JSON encoding error: " . $error_message);
    }
    file_put_contents($filePath, $json_data);
};
$saveJson(['orders' => $orderData], 'retrieved-data.json');
$setItemPayload = json_encode([
    "start" => 0,
    "length" => 0,
    "fieldsToReturn" => "*"
]);
$setItemData = sendCurlRequest($setItemApiUrl, $token, $setItemPayload);
file_put_contents('debug-setitem-response.json', json_encode($setItemData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$setItemJson = file_get_contents('debug-setitem-response.json');
$setItemData = json_decode($setItemJson, true);
$setItemMap = [];
if (!empty($setItemData)) {
    foreach ($setItemData as $setItem) {
        if (isset($setItem['acIdent']) && isset($setItem['ACCLASSIF'])) {
            $setItemMap[$setItem['acIdent']] = $setItem['ACCLASSIF'];
        }
    }
}
$printedIdsFile = 'printed_order_ids.txt';
if (file_exists($printedIdsFile)) {
    $printedOrderIds = explode(",", file_get_contents($printedIdsFile));
} else {
    $printedOrderIds = [];
}
$filteredOrders = [];
$offers = [];
$processedOrderKeys = [];
$missingData = [];
$ordersToSkip = [];
$itemsToSkip = [];
foreach ($orderData as $item) {
    $orderKey = $item['acKeyView'];
    if (in_array($orderKey, $processedOrderKeys)) {
        continue;
    }
    if (in_array($item['acKeyView'], $printedOrderIds)) {
        continue;
    }
    if ($item['acStatus'] === 'П') {
        $offers[] = $item;
    } else {
        $filteredOrders[] = $item;
    }
    $processedOrderKeys[] = $orderKey;
}
$orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);
file_put_contents('debug-api-response.json', json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
if (empty($orderData) || (isset($orderData['error']) && !empty($orderData['error']))) {
    header('Content-Type: application/json');
    $errorMessage = "No orders found for the specified date.";
    if (isset($orderData['error'])) {
        $errorMessage = "Error from API: ". $orderData['error'];
    }
    echo json_encode(['error' => $errorMessage]);
    exit;
}
$processOrders = function (&$orders) use ($setItemMap, $itemsToSkip, &$missingData) { // Pass $missingData by reference
    foreach ($orders as &$item) {
        $filteredOrderItems = [];
        if (isset($item['Orderitem']) && is_array($item['Orderitem'])) {
            foreach ($item['Orderitem'] as $orderItem) {
                $itemKey = $item['acKeyView'] . '-' . $orderItem['acIdent'];
                if (in_array($itemKey, $itemsToSkip)) {
                    continue;
                }
                $acIdent = $orderItem['acIdent'] ?? null;
                if ($acIdent && isset($setItemMap[$acIdent])) {
                    $orderItem['ACCLASSIF'] = $setItemMap[$acIdent];
                } else {
                    $orderItem['ACCLASSIF'] = "";
                    file_put_contents('missing-acclassif.log', "Missing ACCLASSIF for acIdent: $acIdent\n", FILE_APPEND);
                    $missingData[] = [
                        'orderKey' => $item['acKeyView'],
                        'field' => 'ACCLASSIF',
                        'orderItem' => $orderItem,
                    ];
                }
                if ($orderItem['acDept'] === "") {
                    $missingData[] = [
                        'orderKey' => $item['acKeyView'],
                        'field' => 'acDept',
                        'orderItem' => $orderItem,
                    ];
                }
                if (preg_match('/^7420-\d{2}$/', $orderItem['acIdent'])) {
                    continue;
                }
                $filteredOrderItems[] = $orderItem;
            }
        }
        $item['Orderitem'] = $filteredOrderItems;
        if (isset($item['acNote'])) {
            if (is_string($item['acNote'])) {
                $decodedLatin = preg_replace_callback('/\\\\\'([0-9A-Fa-f]{2})/', function ($matches) {
                    return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'Windows-1251');
                }, $item['acNote']);
                $fullyDecoded = preg_replace_callback('/\\\\u([0-9A-Fa-f]+)/', function ($matches) {
                    $unicodeValue = intval($matches[1]);
                    if ($unicodeValue >= 0 && $unicodeValue <= 0x10FFFF) {
                        // return mb_convert_encoding('&#' . $unicodeValue . ';', 'UTF-8', 'HTML-ENTITIES');
                    } else {
                        // return '';
                    }
                }, $decodedLatin);
                $fullyDecoded = preg_replace('/}\\\fs22\\\\par\\\\pard\\\\plain\\\\ql\\\\sl275\\\\slmult1\\\\sa200{\\\\fs22\\\\cf0/', '<br>', $fullyDecoded);
                $fullyDecoded = str_replace("Times New Roman CYR", "", $fullyDecoded);
                $fullyDecoded = str_replace("Times New Roman", "", $fullyDecoded);
                $fullyDecoded = str_replace(' { ', " <br> ", $fullyDecoded);
                $fullyDecoded = str_replace('{\\*', '', $fullyDecoded);
                $fullyDecoded = str_replace("Arial;", '', $fullyDecoded);
                $fullyDecoded = str_replace("Calibri;", '', $fullyDecoded);
                $fullyDecoded = str_replace("Times New Roman;", '', $fullyDecoded);
                $fullyDecoded = str_replace("Segoe UI;", '', $fullyDecoded);
                $fullyDecoded = str_replace("Verdana;", '', $fullyDecoded);
                $fullyDecoded = str_replace(" Normal;", '', $fullyDecoded);
                $fullyDecoded = str_replace(" Default Paragraph Font;", '', $fullyDecoded);
                $fullyDecoded = str_replace(" Line Number;", '', $fullyDecoded);
                $fullyDecoded = str_replace(" Hyperlink;", '', $fullyDecoded);
                $fullyDecoded = str_replace(" Normal Table;", '', $fullyDecoded);
                $fullyDecoded = str_replace(" Table Simple 1;", '', $fullyDecoded);
                $fullyDecoded = str_replace('}', " ", $fullyDecoded);
                $fullyDecoded = str_replace('{\cf2', "</b>", $fullyDecoded);
                $fullyDecoded = str_replace('{\b\cf2 ', "<b>", $fullyDecoded);
                $fullyDecoded = str_replace('\\line ', "\n", $fullyDecoded);
                $fullyDecoded = preg_replace('/\\\\[a-z]+\d*/', ' ', $fullyDecoded);
                $fullyDecoded = str_replace('{\* _dx_frag_StartFragment', "", $fullyDecoded);
                $fullyDecoded = str_replace('_dx_frag_StartFragment', "", $fullyDecoded);
                $fullyDecoded = str_replace(' {', "", $fullyDecoded);
                $fullyDecoded = str_replace('{ ', "", $fullyDecoded);
                $fullyDecoded = str_replace('; ', "", $fullyDecoded);
                $fullyDecoded = str_replace('{', "", $fullyDecoded);
                $fullyDecoded = str_replace(';', "", $fullyDecoded);
                $fullyDecoded = str_replace("\r\n", "", $fullyDecoded);
                $fullyDecoded = str_replace("\x00", "", $fullyDecoded);
                $fullyDecoded = preg_replace("/\s?Msftedit\s\d\.\d{2}\.\d{2}\.\d{4}/", '', $fullyDecoded);
                $fullyDecoded = preg_replace("/\s?Riched20\s\d{2}\.\d\.\d{5}/", '', $fullyDecoded);
                $fullyDecoded = preg_replace('/\s+/', ' ', $fullyDecoded);
                $fullyDecoded = trim($fullyDecoded);
                $item['acNote'] = $fullyDecoded;
            }
        }
        $printedOrderIds[] = $item['acKeyView'];
        unset($item['SetItem']);
        $filteredOrders[] = $item;
    }
};
$processOrders($filteredOrders);
$processOrders($offers);
$dataData = ['orders' => $offers];
file_put_contents('retrieved-data.json', json_encode($dataData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents('retrieved-offers.json', json_encode(['offers' => []]));
echo json_encode(['success' => true]);
$saveJson = function ($data, $filePath) {
    $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json_data === false) {
        $error_message = json_last_error_msg();
        error_log("JSON encoding error: " . $error_message);
        die("JSON encoding error: " . $error_message);
    }
    file_put_contents($filePath, $json_data);
};
$saveJson(['orders' => $filteredOrders], 'retrieved-data.json');
$offersFilePath = 'retrieved-offers.json';
$existingOffers = [];
if (file_exists($offersFilePath)) {
    $existingOffersData = json_decode(file_get_contents($offersFilePath), true);
    if (isset($existingOffersData['offers']) && is_array($existingOffersData['offers'])) {
        $existingOffers = $existingOffersData['offers'];
    }
}
function offerExists($offers, $acKeyView) {
    foreach ($offers as $offer) {
        if ($offer['acKeyView'] === $acKeyView) {
            return true;
        }
    }
    return false;
}
$allOffers = $existingOffers;
$newOffersToAdd = [];
foreach ($offers as $offer) {
    if (!offerExists($allOffers, $offer['acKeyView'])) {
        $newOffersToAdd[] = $offer;
    } else {
        error_log("Offer with acKeyView {$offer['acKeyView']} already exists. Skipping.");
    }
}
$allOffers = array_merge($allOffers, $newOffersToAdd);
$saveJson(['offers' => $allOffers], $offersFilePath);
$clickup_api_key = 'pk_164563706_VIM4ZPFTGVB92NNNXSA0PJDAW1IOBLQ2';
$workspace_source_id = '90122809810';
$workspace_target_ids = [
    '90123265023', // Target Workspace for RAMNI POVRSHINI
    '90123266166', // Target Workspace for TAPETARIJA
    '90123270272', // Target Workspace for STOLICARA
];
$json_data = file_get_contents('retrieved-data.json');
$data = json_decode($json_data, true);
if (!isset($data['orders']) || !is_array($data['orders'])) {
    die("No orders found in JSON.");
}
$retrievedDataFile = 'retrieved-data.json';
if (file_exists($retrievedDataFile)) {
    $retrievedData = json_decode(file_get_contents($retrievedDataFile), true);
    if (!isset($retrievedData['orders']) || !is_array($retrievedData['orders'])) {
        $retrievedData['orders'] = [];
    }
} else {
    $retrievedData = ['orders' => []];
}
function convertDateToTimestamp($dateString) {
    error_log("Converting date: " . $dateString);
    $date = DateTime::createFromFormat('d.m.Y\TH:i:s', $dateString, new DateTimeZone('Europe/Skopje'));
    if (!$date) {
        error_log("Failed to create DateTime object from: " . $dateString);
        return null;
    }
    return $date->getTimestamp() * 1000;
}
// 87730638, 87730644 => "Производство РАМНИ ПОВРШИНИ",
// 87730586, 87730584 => "Производство ТАПЕТАРИЈА",
// 87730123 => "Производство СТОЛИЧАРА",
// 164563706 => "ADMIN",
function processClickUpTasks($data, $clickup_space_id, $clickup_api_key, $assignees_mapping, $include_user = null, $acDeptFilter = null) {
     $clickUpResults = [];
     $foldersProcessed = []; // To avoid duplicate folder entries in $clickUpResults

     foreach ($data['orders'] as $order) {
         if (!isset($order['Orderitem']) || !is_array($order['Orderitem'])) {
             continue;
         }
         $folder_name = '⭕' . $order['acKeyView'] . ' ' . $order['acReceiver'];
         $folder_key = $order['acKeyView']; // Use a unique identifier for the order as the key

         if (!isset($foldersProcessed[$folder_key])) {
             $folder_data = createClickUpFolder($clickup_space_id, $folder_name, $clickup_api_key);
             $folder_id = isset($folder_data['id']) ? $folder_data['id'] : null;
             error_log("ClickUp: Folder processed: " . $folder_name . (isset($folder_id) ? " (ID: " . $folder_id . ")" : " (Failed)"));

             $folderInfo = [
                 'name' => $folder_name,
                 'id' => $folder_id,
                 'lists' => [],
             ];

             foreach ($order['Orderitem'] as $orderItem) {
                 $acDept = $orderItem['acDept'];
                 if ($acDeptFilter !== null && $orderItem['acDept'] !== $acDeptFilter) {
                     continue;
                 }
                 $acDeptClean = str_replace("Производство ", "", $acDept);
                 $list_name = '⭕' . $orderItem['acIdent'] . ' ' . $orderItem['acName'];
                 $list_data = createClickUpList($folder_id, $list_name, $clickup_api_key, isset($assignees_mapping[$acDept]) ? $assignees_mapping[$acDept] : []);
                 $list_id = isset($list_data['id']) ? $list_data['id'] : null;
                 error_log("ClickUp: List processed for " . $folder_name . ": " . $list_name . (isset($list_id) ? " (ID: " . $list_id . ")" : " (Failed)"));

                 $folderInfo['lists'][] = [
                     'name' => $list_name,
                     'id' => $list_id,
                 ];

                 // Task creation remains the same, but we are now structuring $clickUpResults differently
                 $due_date = convertDateToTimestamp($order['adDeliveryDate']);
                 $start_date = convertDateToTimestamp($order['adDate']);
                 $task_data = [
                     'name' => "{$acDeptClean} {$orderItem['acIdent']} {$orderItem['acName']}",
                     'description' => $order['acNote'],
                     'tags' => ["{$orderItem['anQty']}"],
                     'status' => 'незавршен',
                     'due_date' => $due_date,
                     'due_date_time' => false,
                     'time_estimate' => 0,
                     'start_date' => $start_date,
                     'start_date_time' => false,
                     'notify_all' => false,
                     'parent' => null,
                     'links_to' => null,
                 ];
                 if (isset($assignees_mapping[$acDept][0])) {
                     $task_data['assignees'] = $assignees_mapping[$acDept];
                 }
                 $clickup_task_id = createClickUpTaskWithPhases($list_id, $task_data, $clickup_api_key);
                 if ($clickup_task_id) {
                     error_log("ClickUp: Task created with phases: " . $clickup_task_id);
                 } else {
                     error_log("ClickUp: Task creation failed for list: " . $list_name);
                 }
             }
             $clickUpResults[] = $folderInfo;
             $foldersProcessed[$folder_key] = true;
         }
     }
     return $clickUpResults;
 }
function addUserToWorkspace($workspace_id, $user_id, $api_key) {
    $url = "https://api.clickup.com/api/v2/workspace/{$workspace_id}/member/{$user_id}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: {$api_key}",
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        error_log("cURL Error adding user to workspace: " . $curlError . " for User ID: " . $user_id);
        return false;
    }
    if ($httpCode != 200) {
        error_log("HTTP Error adding user to workspace ($httpCode): " . $response . " for User ID: " . $user_id);
        return false;
    }
    return true;
}
function shareClickUpFolder($folder_id, $user_id, $api_key) {
    $url = "https://api.clickup.com/api/v2/folder/{$folder_id}/member/{$user_id}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: {$api_key}",
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    error_log("ClickUp: Folder share response: " . $response);
    curl_close($ch);
    if ($curlError) {
        error_log("cURL Error sharing folder: " . $curlError . " for Folder ID: " . $folder_id);
        return false;
    }
    if ($httpCode != 200) {
        error_log("HTTP Error sharing folder ($httpCode): " . $response . " for Folder ID: " . $folder_id);
        return false;
    }
    return true;
}
function shareClickUpList($list_id, $user_id, $api_key) {
    $url = "https://api.clickup.com/api/v2/list/{$list_id}/member/{$user_id}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: {$api_key}",
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    error_log("ClickUp: List share response: " . $response);
    curl_close($ch);
    if ($curlError) {
        error_log("cURL Error sharing list: " . $curlError . " for List ID: " . $list_id);
        return false;
    }
    if ($httpCode != 200) {
        error_log("HTTP Error sharing list ($httpCode): " . $response . " for List ID: " . $list_id);
        return false;
    }
    return true;
}
function shareClickUpTask($task_id, $user_id, $can_edit, $can_view, $api_key) {
    $url = "https://api.clickup.com/api/v2/task/{$task_id}/shared";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: {$api_key}",
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'user_id' => $user_id,
        'can_edit' => $can_edit,
        'can_view' => $can_view,
    ]));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        error_log("cURL Error sharing task: " . $curlError . " for Task ID: " . $task_id);
        return false;
    }
    if ($httpCode != 200) {
        error_log("HTTP Error sharing task ($httpCode): " . $response . " for Task ID: " . $task_id);
        return false;
    }
    return true;
}
function createClickUpFolder($space_id, $folder_name, $api_key, $add_members = []) {
    $folder_url = "https://api.clickup.com/api/v2/space/$space_id/folder";
    $ch = curl_init($folder_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $api_key", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    $post_data = [
        'name' => $folder_name,
        'private' => true,
    ];
    if (!empty($add_members)) {
        $post_data['add_members'] = $add_members;
    }
    error_log(json_encode($post_data));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    error_log($response);
    $responseData = json_decode($response, true);
    if ($curlError) {
        error_log("cURL Error creating folder: " . $curlError);
        return ['error' => "cURL Error: " . $curlError];
    }
    if ($httpCode != 200) {
        error_log("HTTP Error creating folder ($httpCode): " . $response);
        if (isset($responseData['err'])) {
            return ['error' => "ClickUp API Error ($httpCode): " . $responseData['err']];
        } else {
            return ['error' => "HTTP Error ($httpCode): " . $response];
        }
    }
    return $responseData;
}
function createClickUpList($folder_id, $list_name, $api_key, $add_members = []) {
    $list_url = "https://api.clickup.com/api/v2/folder/$folder_id/list";
    $ch = curl_init($list_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $api_key", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    $post_data = [
        'name' => $list_name,
        'private' => true,
    ];
    if (!empty($add_members)) {
        $post_data['add_members'] = $add_members;
    }
    error_log(json_encode($post_data));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    error_log($response);
    $responseData = json_decode($response, true);
    if ($curlError) {
        error_log("cURL Error creating list: " . $curlError);
        return ['error' => "cURL Error: " . $curlError];
    }
    if ($httpCode != 200) {
        error_log("HTTP Error creating list ($httpCode): " . $response);
        if (isset($responseData['err'])) {
            return ['error' => "ClickUp API Error ($httpCode): " . $responseData['err']];
        } else {
            return ['error' => "HTTP Error ($httpCode): " . $response];
        }
    }
    return $responseData;
}
error_log("ClickUp: acDept: " . $acDept);
error_log("ClickUp: assignees_mapping[$acDept]: " . json_encode($assignees_mapping[$acDept]));
error_log("ClickUp: task_data: " . json_encode($task_data));
error_log("ClickUp: list_id: " . $list_id);
function createClickUpTaskWithPhases($list_id, $task_data, $api_key) {
    $task_url = "https://api.clickup.com/api/v2/list/$list_id/task";
    $ch = curl_init($task_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $api_key", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    $task_data['private'] = true; //set the task as private.
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($task_data));
    $response = curl_exec($ch);
    curl_close($ch);
    $task_response_data = json_decode($response, true);
    if (isset($task_response_data['id'])) {
        $task_id = $task_response_data['id'];
        $phases = [
            ['name' => 'НЕЗАВРШЕН', 'color' => '#c62a2f'],
            ['name' => 'ВО ИЗРАБОТКА', 'color' => '#0880ea'],
            ['name' => 'КОМПЛЕТИРАН', 'color' => '#299764'],
        ];
        foreach ($phases as $phase) {
            $status_url = "https://api.clickup.com/api/v2/task/$task_id/status";
            $ch = curl_init($status_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $api_key", "Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['status' => $phase['name'], 'color' => $phase['color']]));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            if ($curlError) {
                error_log("cURL Error setting status: " . $curlError . " for Task ID: " . $task_id);
            }
            if ($httpCode != 200) {
                error_log("HTTP Error setting status ($httpCode): " . $response . " for Task ID: " . $task_id);
            }
            curl_close($ch);
        }
        return $task_id;
    } else {
        error_log("Task creation failed: " . json_encode($task_response_data));
        return null;
    }
}
$assignees_mapping = [
    "Производство РАМНИ ПОВРШИНИ" => [87730644], // "Производство РАМНИ ПОВРШИНИ" => [87730638, 87730644],
    "Производство ТАПЕТАРИЈА" => [87730123], // "Производство ТАПЕТАРИЈА" => [87730586, 87730584],
    "Производство СТОЛИЧАРА" => [164563706], // "Производство ТАПЕТАРИЈА" => [87730586, 87730584],
];
if (isset($_POST['import_offers'])) {
    error_log("import_offers data received: " . $_POST['import_offers']);
    $selectedOffers = explode(',', $_POST['import_offers']);
    error_log("Selected offers: " . print_r($selectedOffers, true));
    if (!file_exists('retrieved-offers.json')) {
        error_log("retrieved-offers.json does not exist.");
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'retrieved-offers.json does not exist.']);
        exit;
    }
    $offersJson = file_get_contents('retrieved-offers.json');
    if ($offersJson === false) {
        error_log("Error reading retrieved-offers.json");
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error reading retrieved-offers.json']);
        exit;
    }
    $offersData = json_decode($offersJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'JSON decode error: ' . json_last_error_msg()]);
        exit;
    }
    error_log("Contents of retrieved-offers.json: " . print_r($offersData, true));
    $success = true;
    $message = "";
    if (isset($offersData['offers']) && is_array($offersData['offers'])) {
        $importedOffers = [];
        $remainingOffers = [];
        foreach ($offersData['offers'] as $offer) {
            if (in_array($offer['acKeyView'], $selectedOffers)) {
                $importedOffers[] = $offer;
            } else {
                $remainingOffers[] = $offer;
            }
        }
        foreach ($importedOffers as $offer) {
            foreach ($offer['Orderitem'] as $orderItem) {
                $clickup_task_id = createClickUpTaskWithPhases($list_id, $task_data, $clickup_api_key);
                if (!$clickup_task_id) {
                    $success = false;
                    $message .= "Failed to create ClickUp task for offer ID: " . $offer['acKeyView'] . ". ";
                    error_log("Failed to create ClickUp task for offer ID: " . $offer['acKeyView']);
                    break 2; // Break out of both loops
                } else {
                    error_log("ClickUp task created for offer ID: " . $offer['acKeyView'] . " and order item: " . $orderItem['acIdent']);
                }
            }
        }
        if ($success) {
            error_log("Attempting to write to retrieved-offers.json");
            $offersJsonEncoded = json_encode(['offers' => $remainingOffers], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if(json_last_error() !== JSON_ERROR_NONE){
                error_log("JSON encode error while writing to retrieved-offers.json: ". json_last_error_msg());
            }
            file_put_contents('retrieved-offers.json', $offersJsonEncoded);
            error_log("retrieved-offers.json updated.");
            $retrievedDataFile = 'retrieved-data.json';
            $retrievedData = [];
            error_log("Attempting to read retrieved-data.json");
            if (file_exists($retrievedDataFile)) {
                $retrievedData = json_decode(file_get_contents($retrievedDataFile), true);
                 if(json_last_error() !== JSON_ERROR_NONE){
                    error_log("JSON decode error while reading retrieved-data.json: ". json_last_error_msg());
                }
                error_log("retrieved-data.json read successfully.");
            } else {
                error_log("retrieved-data.json does not exist.");
            }
            if (!isset($retrievedData['orders']) || !is_array($retrievedData['orders'])) {
                $retrievedData['orders'] = [];
            }
            $retrievedData['orders'] = array_merge($retrievedData['orders'], $importedOffers);
            error_log("Attempting to write to retrieved-data.json");
            $retrievedDataJsonEncoded = json_encode($retrievedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if(json_last_error() !== JSON_ERROR_NONE){
                error_log("JSON encode error while writing to retrieved-data.json: ". json_last_error_msg());
            }
            file_put_contents($retrievedDataFile, $retrievedDataJsonEncoded);
            error_log("retrieved-data.json updated.");
        }
    } else {
        $success = false;
        $message = "Invalid offers data.";
        error_log("Invalid offers data.");
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
} else {
    error_log("import_offers data NOT received.");
}
error_log("End of script execution.");
error_log("save_data received: " . print_r($_POST['save_data'], true));
if (isset($_POST['save_data'])) {
    $data = json_decode($_POST['save_data'], true);
    error_log("decoded data: " . print_r($data, true));
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decoding error: " . json_last_error_msg());
        echo json_encode(['success' => false, 'message' => 'JSON decoding error: ' . json_last_error_msg()]);
        exit;
    }
    $file = 'retrieved-data.json';
    if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT)) !== false) {
        error_log("Data saved successfully");
        echo json_encode(['success' => true, 'message' => 'Data saved successfully']);
    } else {
        error_log("Error saving data to file");
        echo json_encode(['success' => false, 'message' => 'Error saving data to file']);
    }
    exit;
}
if (isset($_POST['save_offers'])) {
    $offersData = json_decode($_POST['save_offers'], true);
    error_log("decoded data: " . print_r($offersData, true));
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decoding error: " . json_last_error_msg());
        echo json_encode(['success' => false, 'message' => 'JSON decoding error: ' . json_last_error_msg()]);
        exit;
    }
    $offersFile = 'retrieved-offers.json';
    if (file_put_contents($offersFile, json_encode($offersData, JSON_PRETTY_PRINT)) !== false) {
        echo json_encode(['success' => true, 'message' => 'Offers data saved successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving offers data']);
    }
    exit;
}
if (isset($_POST['date'])) {
    $date = $_POST['date'];
    $orderPayload = buildOrderPayload($date);
    $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);
    if (empty($orderData) || (isset($orderData['error']) && !empty($orderData['error']))) {
        header('Content-Type: application/json');
        if (empty($orderData)) {
            echo json_encode(['message' => 'Нема денешни нарачки. Обиди се повторно покасно.']);
            exit;
        } else {
            $errorMessage = "Error from API: " . $orderData['error'];
            echo json_encode(['error' => $errorMessage]);
            exit;
        }
    }
} elseif (isset($_POST['start_date']) && isset($_POST['end_date'])) {
    $startDateStr = $_POST['start_date'];
    $endDateStr = $_POST['end_date'];
    $startDate = DateTime::createFromFormat('d.m.Y', $startDateStr);
    $endDate = DateTime::createFromFormat('d.m.Y', $endDateStr);
    if ($startDate && $endDate) {
        $formattedStartDate = $startDate->format('Y-m-d');
        $formattedEndDate = $endDate->format('Y-m-d');
        $orderPayload = buildOrderPayload(null, $formattedStartDate, $formattedEndDate);
        $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);
        if (empty($orderData) || (isset($orderData['error']) && !empty($orderData['error']))) {
            header('Content-Type: application/json');
            $errorMessage = "No orders found for the specified period.";
            if (isset($orderData['error'])) {
                $errorMessage = "Error from API: " . $orderData['error'];
            }
            echo json_encode(['error' => $errorMessage]);
            exit;
        }
        $saveJson = json_encode(['orders' => $orderData], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $data = ['orders' => $orderData];
    } else {
        error_log("Date parsing error: Invalid date format received.");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
    }
} else {
    error_log("Missing date parameters in request.");
    http_response_code(400);
    echo json_encode(['error' => 'Missing date parameters']);
}
// $clickUpResults = [];
$clickUpResults = processClickUpTasks($data, $clickup_space_id, $clickup_api_key, $assignees_mapping);
$json_data = file_get_contents('retrieved-data.json');
$data = json_decode($json_data, true);
$clickUpResultsRP = processClickUpTasks($data, '90123265023', $clickup_api_key, $assignees_mapping, 87730644, "Производство РАМНИ ПОВРШИНИ");
$clickUpResultsTP = processClickUpTasks($data, '90123266166', $clickup_api_key, $assignees_mapping, 87730123, "Производство ТАПЕТАРИЈА");
$clickUpResultsST = processClickUpTasks($data, '90123270272', $clickup_api_key, $assignees_mapping, 164563706, "Производство СТОЛИЧАРА");
$clickUpResultsJL = processClickUpTasks($data, '90122809810', $clickup_api_key, $assignees_mapping);
if ($missingData) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing data', 'missingData' => $missingData]);
    exit;
}
// header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
$response = [
    'message' => 'Orders processed and ClickUp tasks created/updated (if applicable).',
    'clickUpResultsRP' => $clickUpResultsRP,
    'clickUpResultsTP' => $clickUpResultsTP,
    'clickUpResultsST' => $clickUpResultsST,
    'clickUpResultsJL' => $clickUpResultsJL,
    'orders' => $filteredOrders,
];
echo json_encode($response);
?>