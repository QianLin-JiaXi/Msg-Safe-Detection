<?php
// 检测配置

define('AI_SENSCONTDETPROMPT', 'AI提示词'); // 先行的AI提示词，如果本地有特殊质询逻辑，则可以选择性忽略
define('AI_API_KEYS', ['123', '321']); // 可以直接API轮询
define('ALL_DETECTION_TYPES', ['text', 'image']);

// APIkey配置（用于测试，因此写死在脚本中）
define('VALID_API_KEYS', [
    [
        'key' => 'sk-123',
        'allowed_types' => ['text'],
    ],
    'sk-321'
]);

// 文本检测函数（基于https://github.com/QianLin-Jiaxi/AI-Model-Transfer-Station的改进轮询函数）
function detectSensitiveContent($message) {
    $apiConfigs = AI_API_KEYS;
    $lastError = null;

    foreach ($apiConfigs as $cfg) {
        try {
            $ch = curl_init();
            $requestData = [
                'model'       => $cfg['model'],
                'messages'    => [
                    ['role' => 'system', 'content' => AI_SENSCONTDETPROMPT],
                    ['role' => 'user', 'content' => $message]
                    // 实际上这部分的数据需要使用更加复杂的质询逻辑才能达成较好的检测逻辑，因此建议本地部署提供商，可搭配工具tool一起使用增加AI检测的程度
                ],
                'temperature' => 0.2
            ];
            curl_setopt_array($ch, [
                CURLOPT_URL            => $cfg['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $cfg['key']
                ],
                CURLOPT_POSTFIELDS     => json_encode($requestData),
                CURLOPT_TIMEOUT        => 50,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || $curlError) {
                $lastError = $curlError ?: 'CURL请求失败';
                continue;
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $lastError = "HTTP状态码: $httpCode";
                continue;
            }

            $result = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $lastError = 'JSON解析失败: ' . json_last_error_msg();
                continue;
            }

            if (!isset($result['choices'][0]['message']['content'])) {
                $lastError = '响应结构不完整';
                continue;
            }

            $aiData = json_decode($result['choices'][0]['message']['content'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['sensitiveValue' => 0.0, 'reason' => 'AI响应格式异常'];
            }

            $val = isset($aiData['sensitiveValue']) ? (float)$aiData['sensitiveValue'] : 0.0;
            $val = max(0, min(1, $val));
            $reason = trim($aiData['reason'] ?? '无具体原因说明');

            return ['sensitiveValue' => $val, 'reason' => $reason];

        } catch (Exception $e) {
            $lastError = $e->getMessage();
            continue;
        }
    }

    throw new Exception('所有AI API请求均失败，最后错误: ' . $lastError);
}

// 图片检测函数（需要使用本地自行部署的可用检测服务，这里仅提供一个开发者本地部署的实例）
function detectSensitiveImage($imageUrl) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => '{需要使用本地自行部署的可用检测服务}',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['image_Url' => $imageUrl]),
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception('图片检测API返回错误状态码: ' . $httpCode);
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('解析图片检测响应失败');
    }
    if (!isset($result['code']) || $result['code'] != 1) {
        throw new Exception('图片检测API错误: ' . ($result['msg'] ?? '未知'));
    }

    return [
        'isSafe' => (bool)($result['isSafe'] ?? true),
        'reason' => $result['reason'] ?? ''
    ];
}

// 辅助函数
function getAuthorizationHeader() {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            return $headers['Authorization'];
        }
    }
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    return null;
}

function getAllowedTypesForKey($apiKey) {
    foreach (VALID_API_KEYS as $config) {
        $key = is_array($config) ? $config['key'] : $config;
        if ($key === $apiKey) {
            if (is_array($config) && isset($config['allowed_types'])) {
                return $config['allowed_types'];
            }
            return ALL_DETECTION_TYPES;
        }
    }
    return null;
}

// 入口
header('Content-Type: application/json; charset=utf-8'); // 防止类别认错

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => 0, 'message' => '仅支持 POST 请求']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['code' => 0, 'message' => '请求体不是有效的 JSON']);
    exit;
}

$apiKey = null;
$authHeader = getAuthorizationHeader();
if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $apiKey = trim($matches[1]);
} elseif (!empty($input['api_key'])) {
    $apiKey = trim($input['api_key']);
}

if (empty($apiKey)) {
    http_response_code(401);
    echo json_encode(['code' => 0, 'message' => '缺少 API Key，请在 Authorization 头中使用 Bearer <key> 格式提供']);
    exit;
}

$allowedTypes = getAllowedTypesForKey($apiKey);
if ($allowedTypes === null) {
    http_response_code(403);
    echo json_encode(['code' => 0, 'message' => '无效的 API Key']);
    exit;
}

$type = $input['type'] ?? '';
if (empty($type)) {
    http_response_code(400);
    echo json_encode(['code' => 0, 'message' => '缺少 type 参数']);
    exit;
}

if (!in_array($type, $allowedTypes, true)) {
    http_response_code(403);
    echo json_encode(['code' => 0, 'message' => "此 API Key 无权访问 {$type} 检测，请使用允许的类型: " . implode(', ', $allowedTypes)]);
    exit;
}

switch ($type) {
    case 'text':
        $contentText = $input['content']['text'] ?? '';
        if (trim($contentText) === '') {
            http_response_code(400);
            echo json_encode(['code' => 0, 'message' => '缺少 content.text 参数']);
            exit;
        }
        try {
            $result = detectSensitiveContent($contentText);
            $safe = ($result['sensitiveValue'] <= 0.73); // 可以自行调整
            echo json_encode([
                'code' => 1,
                'data' => [
                    'safe'           => $safe,
                    'sensitiveValue' => $result['sensitiveValue'],
                    'reason'         => $result['reason']
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['code' => 0, 'message' => '检测失败: ' . $e->getMessage()]);
        }
        break;

    case 'image':
        $imageUrl = $input['content']['imageUrl'] ?? '';
        if (empty($imageUrl)) {
            http_response_code(400);
            echo json_encode(['code' => 0, 'message' => '缺少 content.imageUrl 参数']);
            exit;
        }
        try {
            $imgResult = detectSensitiveImage($imageUrl);
            echo json_encode([
                'code' => 1,
                'data' => [
                    'safe'   => $imgResult['isSafe'],
                    'reason' => $imgResult['isSafe'] ? '无敏感内容' : '包含敏感内容' // 这里为了方便使用预设的数值，可以自行选择是否使用真实原因
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['code' => 0, 'message' => '图片检测失败: ' . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['code' => 0, 'message' => "不支持的检测类型: {$type}"]);
}
?>