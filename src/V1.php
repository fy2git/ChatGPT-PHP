<?php

namespace HaoZiTeam\ChatGPT;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\Uuid;

class V1
{
    private string $baseUrl = 'https://apps.openai.com/';

    private array $accounts = [];

    private mixed $http;

    public function __construct(string $baseUrl = null, int $timeout = 360)
    {
        if ($baseUrl) {
            $this->baseUrl = $baseUrl;
        }

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $timeout,
            'stream' => true,
        ]);
    }

    /**
     * 设置账号
     * @param string $accessToken
     * @param mixed $name
     * @param bool $paid
     * @return void
     */
    public function addAccount(string $accessToken, mixed $name = null, bool $paid = false): void
    {
        if ($name === null) {
            $this->accounts[] = [
                'access_token' => $accessToken,
                'paid' => $paid,
            ];
        } else {
            $this->accounts[$name] = [
                'access_token' => $accessToken,
                'paid' => $paid,
            ];
        }
    }

    /**
     * 获取账号
     * @param string $name
     * @return array
     */
    public function getAccount(string $name): array
    {
        return $this->accounts[$name];
    }

    /**
     * 获取所有账号
     * @return array
     */
    public function getAccounts(): array
    {
        return $this->accounts;
    }

    /**
     * 发送消息
     * @param string $prompt
     * @param string|null $conversationId
     * @param string|null $parentId
     * @param mixed $account
     * @param bool $stream
     * @return array|StreamInterface
     * @throws Exception
     */
    public function ask(string $prompt, string $conversationId = null, string $parentId = null, mixed $account = null, bool $stream = false): StreamInterface|array
    {
        // 如果账号为空，则随机选择一个账号
        if ($account === null) {
            $account = array_rand($this->accounts);

            try {
                $token = $this->accessTokenToJWT($this->accounts[$account]['access_token']);
            } catch (Exception) {
                throw new Exception("Account " . $account . " is invalid");
            }
        } else {
            $token = isset($this->accounts[$account]['access_token']) ? $this->accessTokenToJWT($this->accounts[$account]['access_token']) : null;
        }

        // 如果账号为空，则抛出异常
        if ($token === null) {
            throw new Exception("No account available");
        }

        // 设置了父消息ID，必须设置会话ID
        if ($parentId !== null && $conversationId === null) {
            throw new Exception("conversation_id must be set once parent_id is set");
        }

        // 如果会话ID与父消息ID都为空，则开启新的会话
        if ($conversationId === null && $parentId === null) {
            $parentId = (string)Uuid::uuid4();
        }

        // 如果会话ID不为空，但是父消息ID为空，则尝试从ChatGPT获取历史记录
        if ($conversationId !== null && $parentId === null) {
            try {
                $response = $this->http->get('api/conversation/' . $conversationId, [
                    'headers' => [
                        'Authorization' => $token,
                    ],
                ]);
            } catch (GuzzleException $e) {
                throw new Exception("Request failed: " . $e->getMessage());
            }

            $response = json_decode($response->getBody()->getContents(), true);
            if (isset($response['current_node'])) {
                // 如果获取到了父消息ID，则使用该父消息ID
                $conversationId = $response['current_node'];
            } else {
                // 如果没有获取到父消息ID，则开启新的会话
                $conversationId = null;
                $parentId = (string)Uuid::uuid4();
            }
        }

        $data = [
            'action' => 'next',
            'messages' => [
                [
                    'id' => (string)Uuid::uuid4(),
                    'role' => 'user',
                    'content' => ['content_type' => 'text', 'parts' => [$prompt]],
                ],
            ],
            'conversation_id' => $conversationId,
            'parent_message_id' => $parentId,
            'model' => $this->accounts[$account]['paid'] ? 'text-davinci-002-render-paid' : 'text-davinci-002-render-sha',
        ];

        try {
            $response = $this->http->post(
                'api/conversation',
                [
                    'json' => $data,
                    'headers' => [
                        'Authorization' => $token,
                        'Accept' => 'text/event-stream',
                        'Content-Type' => 'application/json',
                        'X-Openai-Assistant-App-Id' => '',
                        'Connection' => 'close',
                        'Accept-Language' => 'en-US,en;q=0.9',
                        'Referer' => 'https://chatbot.openai.com/chat',
                    ],
                    'stream' => true,
                ]
            );
        } catch (GuzzleException $e) {
            throw new Exception($e->getMessage());
        }

        // 如果是数据流模式，则直接返回数据流
        if ($stream) {
            return $response->getBody();
        }

        $answer = '';
        $conversationId = '';
        $parentId = '';
        $model = '';

        foreach (explode("\n", $response->getBody()) as $line) {
            $line = trim($line);
            if ($line === 'Internal Server Error') {
                throw new Exception($line);
            }
            if ($line === '') {
                continue;
            }

            $line = $this->formatStreamMessage($line);

            if (! $this->checkFields($line)) {
                if (isset($line["detail"]) && $line["detail"] === "Too many requests in 1 hour. Try again later.") {
                    throw new Exception("Rate limit exceeded");
                }
                if (isset($line["detail"]) && $line["detail"] === "Conversation not found") {
                    throw new Exception("Conversation not found");
                }
                if (isset($line["detail"]) && $line["detail"] === "Something went wrong, please try reloading the conversation.") {
                    throw new Exception("Something went wrong, please try reloading the conversation.");
                }
                if (isset($line["detail"]) && $line["detail"] === "invalid_api_key") {
                    throw new Exception("Invalid access token");
                }
                if (isset($line["detail"]) && $line["detail"] === "invalid_token") {
                    throw new Exception("Invalid access token");
                }

                continue;
            }

            if ($line['message']['content']['parts'][0] === $prompt) {
                continue;
            }

            $answer = $line['message']['content']['parts'][0];
            $conversationId = $line['conversation_id'] ?? null;
            $parentId = $line['message']['id'] ?? null;
            $model = $line["message"]["metadata"]["model_slug"] ?? null;
        }

        return [
            'answer' => $answer,
            'conversation_id' => $conversationId,
            'parent_id' => $parentId,
            'model' => $model,
            'account' => $account,
        ];
    }

    /**
     * 获取会话列表
     * @param int $offset
     * @param int $limit
     * @param mixed $account
     * @return array
     * @throws Exception
     */
    public function getConversations(int $offset = 0, int $limit = 20, mixed $account = 0): array
    {
        try {
            $token = $this->accessTokenToJWT($this->accounts[$account]['access_token']);
        } catch (Exception) {
            throw new Exception("Invalid account");
        }

        try {
            $response = $this->http->get('api/conversations', [
                'headers' => [
                    'Authorization' => $token,
                ],
                'query' => [
                    'offset' => $offset,
                    'limit' => $limit,
                ],
            ])->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception($e->getMessage());
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Response is not json');
        }

        if (! isset($data['items'])) {
            throw new Exception('Field missing');
        }

        return $data['items'];
    }

    /**
     * 获取会话消息列表
     * @param string $conversationId
     * @param mixed $account
     * @return array
     * @throws Exception
     */
    public function getConversationMessages(string $conversationId, mixed $account = 0): array
    {
        try {
            $token = $this->accessTokenToJWT($this->accounts[$account]['access_token']);
        } catch (Exception) {
            throw new Exception("Invalid account");
        }

        try {
            $response = $this->http->get('api/conversation/' . $conversationId, [
                'headers' => [
                    'Authorization' => $token,
                ],
            ])->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception($e->getMessage());
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Response is not json');
        }

        return $data;
    }

    /**
     * 生成会话标题
     * @param string $conversationId
     * @param string $messageId
     * @param mixed $account
     * @return bool
     * @throws Exception
     */
    public function generateConversationTitle(string $conversationId, string $messageId, mixed $account = 0): bool
    {
        try {
            $token = $this->accessTokenToJWT($this->accounts[$account]['access_token']);
        } catch (Exception) {
            throw new Exception("Invalid account");
        }

        try {
            $response = $this->http->post('api/conversation/gen_title/' . $conversationId, [
                'headers' => [
                    'Authorization' => $token,
                ],
                'json' => [
                    'message_id' => $messageId,
                    'model' => 'text-davinci-002-render',
                ],
            ])->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception($e->getMessage());
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Response is not json');
        }

        if (isset($data['title'])) {
            return true;
        }

        return false;
    }

    /**
     * 修改会话标题
     * @param string $conversationId
     * @param string $title
     * @param mixed $account
     * @return bool
     * @throws Exception
     */
    public function updateConversationTitle(string $conversationId, string $title, mixed $account = 0): bool
    {
        try {
            $token = $this->accessTokenToJWT($this->accounts[$account]['access_token']);
        } catch (Exception) {
            throw new Exception("Invalid account");
        }

        try {
            $response = $this->http->patch('api/conversation/' . $conversationId, [
                'headers' => [
                    'Authorization' => $token,
                ],
                'json' => [
                    'title' => $title,
                ],
            ])->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception($e->getMessage());
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Response is not json');
        }

        if (isset($data['success']) && $data['success'] === true) {
            return true;
        }

        return false;
    }

    /**
     * 删除会话
     * @param string $conversationId
     * @param mixed $account
     * @return bool
     * @throws Exception
     */
    public function deleteConversation(string $conversationId, mixed $account = 0): bool
    {
        try {
            $token = $this->accessTokenToJWT($this->accounts[$account]['access_token']);
        } catch (Exception) {
            throw new Exception("Invalid account");
        }

        try {
            $response = $this->http->patch('api/conversation/' . $conversationId, [
                'headers' => [
                    'Authorization' => $token,
                ],
                'json' => [
                    'is_visible' => false,
                ],
            ])->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception($e->getMessage());
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Response is not json');
        }

        if (isset($data['success']) && $data['success'] === true) {
            return true;
        }

        return false;
    }

    /**
     * 清空会话
     * @param mixed $account
     * @return bool
     * @throws Exception
     */
    public function clearConversations(mixed $account = 0): bool
    {
        try {
            $token = $this->accessTokenToJWT($this->accounts[$account]['access_token']);
        } catch (Exception) {
            throw new Exception("Invalid account");
        }

        try {
            $response = $this->http->patch('api/conversations', [
                'headers' => [
                    'Authorization' => $token,
                ],
                'json' => [
                    'is_visible' => false,
                ],
            ])->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception($e->getMessage());
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Response is not json');
        }

        if (isset($data['success']) && $data['success'] === true) {
            return true;
        }

        return false;
    }

    /**
     * 检查响应行是否包含必要的字段
     * @param mixed $line
     * @return bool
     */
    public function checkFields(mixed $line): bool
    {
        return isset($line['message']['content']['parts'][0])
            && isset($line['conversation_id'])
            && isset($line['message']['id']);
    }

    /**
     * 格式化流消息为数组
     * @param string $line
     * @return array|false
     */
    public function formatStreamMessage(string $line): array|false
    {
        preg_match('/data: (.*)/', $line, $matches);
        if (empty($matches[1])) {
            return false;
        }

        $line = $matches[1];
        $data = json_decode($line, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $data;
    }

    /**
     * access_token 转换为 JWT
     * @param string $accessToken
     * @return string
     * @throws Exception
     */
    private function accessTokenToJWT(string $accessToken): string
    {
        try {
            $sAccessToken = explode(".", $accessToken);
            $sAccessToken[1] .= str_repeat("=", (4 - strlen($sAccessToken[1]) % 4) % 4);
            $dAccessToken = base64_decode($sAccessToken[1]);
            $dAccessToken = json_decode($dAccessToken, true);
        } catch (Exception) {
            throw new Exception("Access token invalid");
        }

        // 检查是否过期
        $exp = $dAccessToken['exp'] ?? null;
        if ($exp !== null && $exp < time()) {
            throw new Exception("Access token expired");
        }

        return 'Bearer ' . $accessToken;
    }
}
