<?php
namespace usualtool\Ai;
class Ai{
    private $UpstreamBaseUrl;
    private $UpstreamApiKey;
    private $MyApiKey;
    private $AllowedModels;
    /**
     * 构造函数：初始化配置
     * @param array $config 配置数组
     */
    public function __construct(array $config = []) {
        if (!empty($config)) {
            $this->UpstreamBaseUrl = $config['upstream_base_url'] ?? '';
            $this->UpstreamApiKey  = $config['upstream_api_key'] ?? '';
            $this->MyApiKey        = $config['my_api_key'] ?? '';
            $this->AllowedModels   = $config['allowed_models'] ?? [];
        } else {
            $this->LoadEnvConfig();
        }
    }
    /**
     * 从 .env 文件加载配置
     */
    private function LoadEnvConfig() {
        $envFile = __DIR__ . '/.env';
        $env = [];
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $env[trim($key)] = trim($value);
                }
            }
        }
        $this->UpstreamBaseUrl = $env['UPSTREAM_BASE_URL'] ?? '';
        $this->UpstreamApiKey  = $env['UPSTREAM_API_KEY'] ?? '';
        $this->MyApiKey        = $env['MY_API_KEY'] ?? '';
        $this->AllowedModels   = isset($env['ALLOWED_MODELS']) 
            ? array_map('trim', explode(',', $env['ALLOWED_MODELS'])) 
            : ['glm-4-flash', 'deepseek-chat', 'qwen-turbo'];
    }
    /**
     * 处理请求
     */
    public function HandleRequest() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($uri, PHP_URL_PATH);

        if (preg_match('#/v1/chat/completions$#', $path) && $method === 'POST') {
            $this->HandleChatCompletions();
        } elseif (preg_match('#/v1/models$#', $path) && $method === 'GET') {
            $this->HandleListModels();
        } else {
            $this->JsonResponse(404, ['error' => ['message' => '未找到接口：' . $path, 'type' => 'invalid_request_error']]);
        }
    }
    /**
     * 聊天补全
     * @param array $messages 消息数组
     * @param string $model 模型名称
     * @param bool $stream 是否流式
     * @param array $options 其他可选参数
     * @return string 返回 JSON 字符串
     */
    public function Chat(array $messages, string $model = '', bool $stream = false, array $options = []) {
        if (empty($messages)) {
            return json_encode(['error' => ['message' => '缺少 messages 参数']]);
        }
        if (empty($model)) {
            $model = $this->AllowedModels[0] ?? 'glm-4-flash';
        }
        if (!in_array($model, $this->AllowedModels)) {
            return json_encode(['error' => ['message' => '不支持的模型：' . $model]]);
        }

        $body = array_merge([
            'model'    => $model,
            'messages' => $messages,
            'stream'   => $stream,
        ], $options);

        $url = rtrim($this->UpstreamBaseUrl, '/') . '/chat/completions';

        if ($stream) {
            $this->StreamProxy($url, $body);
            return '';
        } else {
            return $this->NormalProxy($url, $body);
        }
    }
    /**
     * 获取可用模型列表
     * @return string 返回 JSON 字符串
     */
    public function ListModels() {
        $models = [];
        foreach ($this->AllowedModels as $name) {
            $models[] = [
                'id'       => $name,
                'object'   => 'model',
                'created'  => time(),
                'owned_by' => 'personal',
            ];
        }
        return json_encode(['object' => 'list', 'data' => $models]);
    }
    private function HandleChatCompletions() {
        $this->Authenticate();
        $input = $this->GetInput();
        $model = $input['model'] ?? ($this->AllowedModels[0] ?? '');
        if (!in_array($model, $this->AllowedModels)) {
            $this->JsonResponse(400, ['error' => ['message' => '不支持的模型：' . $model]]);
        }
        $body = array_merge([
            'model'    => $model,
            'messages' => $input['messages'],
            'stream'   => !empty($input['stream']),
        ], array_intersect_key($input, array_flip(['temperature', 'top_p', 'max_tokens', 'stop'])));

        $url = rtrim($this->UpstreamBaseUrl, '/') . '/chat/completions';
        if ($body['stream']) {
            $this->StreamProxy($url, $body);
        } else {
            $response = $this->NormalProxy($url, $body);
            echo $response;
        }
    }
    private function HandleListModels() {
        $this->Authenticate();
        echo $this->ListModels();
    }
    private function Authenticate() {
        $token = $this->GetBearerToken();
        if ($token !== $this->MyApiKey) {
            $this->JsonResponse(401, ['error' => ['message' => '无效的 API Key', 'type' => 'authentication_error']]);
        }
    }
    private function GetInput(): array {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['messages']) || !is_array($input['messages'])) {
            $this->JsonResponse(400, ['error' => ['message' => '缺少 messages 参数', 'type' => 'invalid_request_error']]);
        }
        return $input;
    }
    private function NormalProxy(string $url, array $body): string {
        $ch = $this->InitCurl($url, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return json_encode(['error' => ['message' => '上游请求失败：' . $error, 'type' => 'upstream_error']]);
        }
        return $response;
    }
    private function StreamProxy(string $url, array $body) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        $ch = $this->InitCurl($url, $body);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        });
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            echo "data: " . json_encode(['error' => ['message' => '上游流中断：' . $error]]) . "\n\n";
            flush();
        }
    }
    private function InitCurl(string $url, array $body) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->UpstreamApiKey,
            ],
            CURLOPT_TIMEOUT        => 300,
        ]);
        return $ch;
    }
    private function GetBearerToken(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
    private function JsonResponse(int $code, array $data) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
