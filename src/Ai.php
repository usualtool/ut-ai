<?php
namespace usualtool\Ai;
class Ai {
    private $UpstreamBaseUrl;
    private $UpstreamApiKey;
    private $MyApiKey;
    private $AllowedModels;
    private $KnowledgeEnabled;
    private $KnowledgeBaseUrl;
    private $KnowledgeApiKey;
    private $KnowledgeBaseIds;
    private $KnowledgeTopK;
    private $KnowledgeTopN;
    private $KnowledgeEnableRerank;
    private $KnowledgeModel;
    private $EnableThinking;
    private $KnowledgeEnableThinking;
    private $SystemPrompt;
    /**
     * 构造函数：初始化配置
     * @param array $config 配置数组
     */
    public function __construct(array $config = []) {
        if (!empty($config)) {
            $this->UpstreamBaseUrl = $config['upstream_base_url'] ?? '';
            $this->UpstreamApiKey = $config['upstream_api_key'] ?? '';
            $this->MyApiKey = $config['my_api_key'] ?? '';
            $this->AllowedModels = $config['allowed_models'] ?? [];
            $this->KnowledgeEnabled = !empty($config['knowledge_enabled']);
            $this->KnowledgeBaseUrl = $config['knowledge_base_url'] ?? '';
            $this->KnowledgeApiKey = $config['knowledge_api_key'] ?? '';
            $this->KnowledgeBaseIds = $config['knowledge_base_ids'] ?? [];
            $this->KnowledgeTopK = $config['knowledge_top_k'] ?? 8;
            $this->KnowledgeTopN = $config['knowledge_top_n'] ?? 5;
            $this->KnowledgeEnableRerank = !empty($config['knowledge_enable_rerank']);
            $this->KnowledgeModel = $config['knowledge_model'] ?? '';
            $this->EnableThinking = !empty($config['enable_thinking']);
            $this->KnowledgeEnableThinking = !empty($config['knowledge_enable_thinking']);
            $this->SystemPrompt = $this->ParsePrompt($config['system_prompt'] ?? '');
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
        $this->UpstreamApiKey = $env['UPSTREAM_API_KEY'] ?? '';
        $this->MyApiKey = $env['MY_API_KEY'] ?? '';
        $this->AllowedModels = isset($env['ALLOWED_MODELS']) ? array_map('trim', explode(',', $env['ALLOWED_MODELS'])) : ['glm-4-flash', 'deepseek-chat', 'qwen-turbo'];
        $this->KnowledgeEnabled = !empty($env['KNOWLEDGE_ENABLED']);
        $this->KnowledgeBaseUrl = $env['KNOWLEDGE_BASE_URL'] ?? '';
        $this->KnowledgeApiKey = $env['KNOWLEDGE_API_KEY'] ?? '';
        $this->KnowledgeBaseIds = isset($env['KNOWLEDGE_BASE_IDS']) ? array_map('trim', explode(',', $env['KNOWLEDGE_BASE_IDS'])) : [];
        $this->KnowledgeTopK = (int)($env['KNOWLEDGE_TOP_K'] ?? 8);
        $this->KnowledgeTopN = (int)($env['KNOWLEDGE_TOP_N'] ?? 5);
        $this->KnowledgeEnableRerank = !empty($env['KNOWLEDGE_ENABLE_RERANK']);
        $this->KnowledgeModel = $env['KNOWLEDGE_MODEL'] ?? '';
        $this->EnableThinking = !empty($env['ENABLE_THINKING']);
        $this->KnowledgeEnableThinking = !empty($env['KNOWLEDGE_ENABLE_THINKING']);
        $this->SystemPrompt = $this->ParsePrompt($env['SYSTEM_PROMPT'] ?? '');
    }
    /**
     * 解析提示词配置
     * @param mixed $value
     * @return string
     */
    private function ParsePrompt($value): string {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        return str_replace(array('\n', '\r'), array("\n", ''), trim($value));
    }
    /**
     * 消息数组中是否已有 system 消息
     * @param array $messages
     * @return bool
     */
    private function HasSystemMessage(array $messages): bool {
        foreach ($messages as $msg) {
            if (isset($msg['role']) && $msg['role'] === 'system') {
                return true;
            }
        }
        return false;
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
    public function Chat(array $messages, string $model = '', bool $stream = false, string $sessionId='', array $options = []) {
        if (empty($messages)) {
            return json_encode(['error' => ['message' => '缺少 messages 参数']]);
        }
        if (empty($model)) {
            $model = $this->AllowedModels[0] ?? 'glm-4-flash';
        }
        if (!in_array($model, $this->AllowedModels)) {
            return json_encode(['error' => ['message' => '不支持的模型：' . $model]]);
        }
        list($url, $body, $useKnowledge) = $this->BuildChatRequest($messages, $model, $stream, $options);
        if ($stream) {
            $this->StreamProxy($url, $body, $useKnowledge, $sessionId);
            return '';
        } else {
            return $this->NormalProxy($url, $body, $useKnowledge, $sessionId);
        }
    }
    /**
     * 构建上游请求
     * @param array $messages 消息数组
     * @param string $model 模型名称
     * @param bool $stream 是否流式
     * @param array $options 其他可选参数
     * @return array array($url, $body, $useKnowledge)
     */
    private function BuildChatRequest(array $messages, string $model, bool $stream, array $options = []): array {
        $body = array_merge([
            'model' => $model,
            'messages' => $this->NormalizeMessages($messages),
            'stream' => $stream,
        ], $options);
        $useKnowledge = $this->IsKnowledgeEnabled();
        if ($useKnowledge) {
            $url = rtrim($this->KnowledgeBaseUrl, '/') . '/chat';
            $body['retrieval'] = $this->BuildRetrievalParam();
            $body['model'] = $this->KnowledgeModel;
            $body['enable_thinking'] = $this->KnowledgeEnableThinking;
        } else {
            $url = rtrim($this->UpstreamBaseUrl, '/') . '/chat/completions';
            $body['thinking'] = [
                'type' => $this->EnableThinking ? 'enabled' : 'disabled'
            ];
        }
        if (trim($this->SystemPrompt) !== '' && !$this->HasSystemMessage($body['messages'])) {
            array_unshift($body['messages'], [
                'role' => 'system',
                'content' => $this->SystemPrompt,
            ]);
        }
        return [$url, $body, $useKnowledge];
    }
    /**
     * 命令行流式对话
     * @param array $messages 消息数组（末条为本次提问）
     * @param string $model 模型名称
     * @param callable|null $callback 回调函数
     * @param string $sessionId 会话ID（透传上游 X-Session-Id）
     * @param array $options 其他可选参数
     * @return void
     */
    public function Cli(array $messages, string $model = '', ?callable $callback = null, string $sessionId = '', array $options = []){
        $emit = function ($type, $text, $usage = []) use ($callback) {
            if ($callback !== null) {
                call_user_func($callback, $type, $this->ToText($text), is_array($usage) ? $usage : []);
            }
        };
        if (empty($messages)) {
            $emit('error', '缺少 messages 参数');
            $emit('done', '', []);
            return;
        }
        if (empty($model)) {
            $model = $this->AllowedModels[0] ?? 'glm-4-flash';
        }
        if (!in_array($model, $this->AllowedModels)) {
            $emit('error', '不支持的模型：' . $model);
            $emit('done', '', []);
            return;
        }
        list($url, $body, $useKnowledge) = $this->BuildChatRequest($messages, $model, true, $options);
        $base = $useKnowledge ? $this->KnowledgeBaseUrl : $this->UpstreamBaseUrl;
        $apiKey = ($useKnowledge && !empty($this->KnowledgeApiKey)) ? $this->KnowledgeApiKey : $this->UpstreamApiKey;
        if (trim((string)$base) === '') {
            $emit('error', '上游地址为空，请检查 UPSTREAM_BASE_URL'
                . ($useKnowledge ? ' / KNOWLEDGE_BASE_URL' : ''));
            $emit('done', '', []);
            return;
        }
        if ((string)$apiKey === '') {
            $emit('error', '上游密钥为空，请检查 UPSTREAM_API_KEY'
                . ($useKnowledge ? ' / KNOWLEDGE_API_KEY' : ''));
            $emit('done', '', []);
            return;
        }
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $emit('error', '请求体编码失败（' . json_last_error_msg() . '）');
            $emit('done', '', []);
            return;
        }
        $buffer = '';
        $plain = '';
        $usage = [];
        $got = false;
        $onLine = function ($line) use ($emit, $useKnowledge, &$plain, &$usage, &$got) {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            if (stripos($line, 'data:') !== 0) {
                $plain .= $line . "\n";
                return;
            }
            $raw = trim(substr($line, 5));
            if ($raw === '' || $raw === '[DONE]') {
                return;
            }
            $event = json_decode($raw, true);
            if (!is_array($event)) {
                $plain .= $line . "\n";
                return;
            }
            if (isset($event['error'])) {
                $got = true;
                $e = $event['error'];
                if (is_array($e)) {
                    $emit('error', isset($e['message']) ? $e['message'] : json_encode($e, JSON_UNESCAPED_UNICODE));
                } else {
                    $emit('error', $e);
                }
                return;
            }
            if (isset($event['usage']) && is_array($event['usage']) && !empty($event['usage'])) {
                $usage = $event['usage'];
            }
            if ($useKnowledge && isset($event['type'])) {
                switch ($event['type']) {
                    case 'thought':
                    case 'reasoning':
                        if (!$this->KnowledgeEnableThinking) {
                            break;
                        }
                        if (isset($event['data']) && $this->ToText($event['data']) !== '') {
                            $got = true;
                            $emit('reasoning', $event['data']);
                        }
                        break;
                    case 'answer':
                        if (isset($event['data']) && $this->ToText($event['data']) !== '') {
                            $got = true;
                            $emit('content', $event['data']);
                        }
                        break;
                    case 'done':
                        if (isset($event['usage']) && is_array($event['usage'])) {
                            $usage = $event['usage'];
                        }
                        break;
                }
                return;
            }
            if (!isset($event['choices'][0]['delta']) || !is_array($event['choices'][0]['delta'])) {
                return;
            }
            $delta = $event['choices'][0]['delta'];
            if (isset($delta['reasoning_content']) && $this->ToText($delta['reasoning_content']) !== '') {
                $got = true;
                $emit('reasoning', $delta['reasoning_content']);
            }
            if (isset($delta['content']) && $this->ToText($delta['content']) !== '') {
                $got = true;
                $emit('content', $delta['content']);
            }
        };
        $headers = [
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Authorization: Bearer ' . $apiKey,
        ];
        if (!empty($sessionId)) {
            $headers[] = 'X-Session-Id: ' . $sessionId;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer, $onLine) {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = str_replace("\r", '', substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    $onLine($line);
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($buffer !== '') {
            $rest = $buffer;
            $buffer = '';
            $onLine($rest);
        }
        if ($error !== '') {
            $got = true;
            $emit('error', '上游请求失败：' . $error);
        } elseif (!$got && trim($plain) !== '') {
            $emit('error', '上游返回 HTTP ' . $httpCode . '：' . substr(trim($plain), 0, 300));
        } elseif (!$got) {
            $emit('error', '上游未返回任何内容');
        }
        $emit('done', '', $usage);
    }
    /**
     * 把帧内容统一成字符串
     * @param mixed $value
     * @return string
     */
    private function ToText($value): string {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        return $json === false ? '' : $json;
    }
    /**
     * 获取可用模型列表
     * @return string 返回 JSON 字符串
     */
    public function ListModels() {
        $models = [];
        foreach ($this->AllowedModels as $name) {
            $models[] = [
                'id' => $name,
                'object' => 'model',
                'created' => time(),
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
            'model' => $model,
            'messages' => $this->NormalizeMessages($input['messages']),
            'stream' => !empty($input['stream']),
        ], array_intersect_key($input, array_flip(['temperature', 'top_p', 'max_tokens', 'stop'])));
        $useKnowledge = $this->IsKnowledgeEnabled() && isset($input['retrieval']);
        if ($useKnowledge) {
            $url = rtrim($this->KnowledgeBaseUrl, '/') . '/chat';
            $body['retrieval'] = array_merge(
                $this->BuildRetrievalParam(),
                is_array($input['retrieval']) ? $input['retrieval'] : []
            );
            $body['enable_thinking'] = $input['enable_thinking'] ?? $this->KnowledgeEnableThinking;
        } else {
            $url = rtrim($this->UpstreamBaseUrl, '/') . '/chat/completions';
            if (isset($input['thinking']) && is_array($input['thinking'])) {
                $body['thinking'] = $input['thinking'];
            } else {
                $body['thinking'] = [
                    'type' => $this->EnableThinking ? 'enabled' : 'disabled'
                ];
            }
        }
        if ($body['stream']) {
            $this->StreamProxy($url, $body, $useKnowledge);
        } else {
            $response = $this->NormalProxy($url, $body, $useKnowledge);
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
    private function NormalProxy(string $url, array $body, bool $useKnowledge = false, string $sessionId=''): string {
        $ch = $this->InitCurl($url, $body, $useKnowledge,$sessionId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            return json_encode(['error' => ['message' => '上游请求失败：' . $error, 'type' => 'upstream_error']]);
        }
        return $response;
    }
    /**
     * 流式代理转发
     */
    private function StreamProxy(string $url, array $body, bool $useKnowledge = false, string $sessionId='') {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        $ch = $this->InitCurl($url, $body, $useKnowledge,$sessionId);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $responseId = 'chatcmpl-' . uniqid();
        $finalUsage = null;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($useKnowledge, $responseId, &$finalUsage) {
            if ($useKnowledge) {
                static $buffer = '';
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);
                    if (empty($line)) continue;
                    if (strpos($line, 'data:') === 0) {
                        $jsonStr = trim(substr($line, 5));
                        if ($jsonStr === '[DONE]') continue;
                        $event = json_decode($jsonStr, true);
                        if (!$event || !isset($event['type'])) continue;
                        static $currentSessionId = '';
                        if (isset($event['sessionId']) && !empty($event['sessionId'])) {
                            $currentSessionId = $event['sessionId'];
                        }
                        $standardData = [
                            'id' => $responseId,
                            'sessionId' => $currentSessionId,
                            'object' => 'chat.completion.chunk',
                            'created' => time(),
                            'model' => $this->KnowledgeModel,
                            'choices' => [
                                [
                                    'delta' => [],
                                    'index' => 0
                                ]
                            ]
                        ];
                        switch ($event['type']) {
                            case 'thought':
                            case 'reasoning':
                                if (!$this->KnowledgeEnableThinking) {
                                    break;
                                }
                                $standardData['choices'][0]['delta'] = [
                                    'role' => 'assistant',
                                    'reasoning_content' => $event['data']
                                ];
                                echo "data: " . json_encode($standardData, JSON_UNESCAPED_UNICODE) . "\n\n";
                                break;
                            case 'answer': // 正式回答
                                $standardData['choices'][0]['delta'] = [
                                    'role' => 'assistant',
                                    'content' => $event['data']
                                ];
                                echo "data: " . json_encode($standardData, JSON_UNESCAPED_UNICODE) . "\n\n";
                                break;
                            case 'done': // 结束信号
                                if (isset($event['usage'])) {
                                    $finalUsage = $event['usage'];
                                }
                                $finalChunk = $standardData;
                                $finalChunk['choices'][0]['delta'] = [];
                                $finalChunk['choices'][0]['finish_reason'] = 'stop';
                                if ($finalUsage) {
                                    $finalChunk['usage'] = $finalUsage;
                                }
                                echo "data: " . json_encode($finalChunk, JSON_UNESCAPED_UNICODE) . "\n\n";
                                echo "data: [DONE]\n\n";
                                break;
                        }
                    }
                }
                if (ob_get_level()) ob_flush();
                flush();
                return strlen($chunk);
            }
            $chunkToEcho = $chunk;
            if (!$this->EnableThinking) {
                $decoded = json_decode($chunk, true);
                if (isset($decoded['choices'][0]['delta']['reasoning_content'])) {
                    unset($decoded['choices'][0]['delta']['reasoning_content']);
                    $chunkToEcho = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }
            echo $chunkToEcho;
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            return strlen($chunkToEcho);
        });
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            echo "data: " . json_encode(['error' => ['message' => $error]]) . "\n\n";
            flush();
        }
    }
    private function InitCurl(string $url, array $body, bool $useKnowledge = false, string $sessionId='') {
        $ch = curl_init($url);
        $apiKey = ($useKnowledge && !empty($this->KnowledgeApiKey)) ? $this->KnowledgeApiKey : $this->UpstreamApiKey;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '.$apiKey,
        ];
        if(!empty($sessionId)){
            $headers[] = 'X-Session-Id: '.$sessionId;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 300,
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
    /**
     * 规范化 messages 数组
     * 确保 messages 始终是一个索引数组（JSON 数组），而不是关联数组（JSON 对象）
     * @param mixed $messages
     * @return array
     */
    private function NormalizeMessages($messages) {
        if (!is_array($messages)) {
            $messages = [$messages];
        }
        return array_values($messages);
    }
    /**
     * 判断知识库功能是否可用
     * @return bool
     */
    private function IsKnowledgeEnabled(): bool {
        return $this->KnowledgeEnabled && !empty($this->KnowledgeBaseUrl) && !empty($this->KnowledgeBaseIds);
    }
    /**
     * 构建 retrieval 参数
     * @return array
     */
    private function BuildRetrievalParam(): array {
        return [
            'know_ids' => $this->KnowledgeBaseIds,
            'top_k' => $this->KnowledgeTopK,
            'top_n' => $this->KnowledgeTopN,
            'enable_rerank' => $this->KnowledgeEnableRerank,
        ];
    }
}
