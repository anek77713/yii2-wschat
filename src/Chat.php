<?php
namespace WSChat;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * Class Chat
 * @package WSChat
 */
class Chat implements MessageComponentInterface
{
    /** @var ConnectionInterface[] */
    private $clients = [];
    /** @var ChatManager */
    private $cm = null;
    /**
     * @var array list of available requests
     */
    private $requests = [
        'auth', 'message'
    ];

    /**
     * @param ChatManager $cm
     */
    public function __construct(ChatManager $cm)
    {
        $this->cm = $cm;
        $this->cm->refreshChats();
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onOpen(ConnectionInterface $conn)
    {
        $rid = $this->getResourceId($conn);
        echo 'Connection is established: '.$rid.PHP_EOL;
        $this->clients[$rid] = $conn;
        $this->cm->addUser($rid);
    }

    /**
     * @param ConnectionInterface $from
     * @param string $msg
     */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        $rid = array_search($from, $this->clients);
        if (in_array($data['type'], $this->requests)) {
            call_user_func_array([$this, $data['type'].'Request'], [$rid, $data['data']]);
        }
    }

    /**
     * @param ConnectionInterface $conn
     */
    public function onClose(ConnectionInterface $conn)
    {
        $rid = array_search($conn, $this->clients);
        echo 'Connection is closed '.$rid.PHP_EOL;
        if ($this->cm->getUserByRid($rid)) {
            $this->cm->removeUserFromChat($rid);
        }
        unset($this->clients[$rid]);
    }

    /**
     * @param ConnectionInterface $conn
     * @param \Exception $e
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $conn->close();
    }

    /**
     * Get connection resource id
     *
     * @access private
     * @param ConnectionInterface $conn
     * @return string
     */
    private function getResourceId(ConnectionInterface $conn)
    {
        return $conn->resourceId;
    }

    /**
     * Process auth request. Find user chat(if not exists - create it)
     * and send message to all other clients
     *
     * @access private
     * @param $rid
     * @param $data
     * @return void
     */
    private function authRequest($rid, array $data)
    {
        echo 'Auth request from user: '.$rid.' and chat: '.$data['cid'].PHP_EOL;
        $chat = $this->cm->findChat($data['cid'], $rid);
        echo 'Count of users: '.sizeof($chat->getUsers()).PHP_EOL;
        foreach ($chat->getUsers() as $user) {
            $conn = $this->clients[$user->getRid()];
            $conn->send(json_encode(['type' => 'message', 'data' => ['message' => $data['message'], 'join' => true]]));
        }
    }

    /**
     * Process message request. Find user chat room and send message to other users
     * in this chat room
     *
     * @access private
     * @param $rid
     * @param array $data
     * @return void
     */
    private function messageRequest($rid, array $data)
    {
        echo 'Message from: '.$rid.PHP_EOL;
        $chat = $this->cm->getUserChat($rid);
        if (!$chat) {
            echo 'Chat for '.$rid.' not found'.PHP_EOL;
            return;
        }
        foreach ($chat->getUsers() as $user) {
            //need not to send message for self
            if ($user->getRid() == $rid) {
                continue;
            }
            $conn = $this->clients[$user->getRid()];
            $conn->send(json_encode(['type' => 'message', 'data' => $data]));
        }
    }
}
 