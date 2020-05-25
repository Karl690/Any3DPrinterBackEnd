<?php
define('HOST_NAME', "localhost");
define('PORT', "8090");
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'any3dprinter');

$null = NULL;

require_once("database.php");

$dbManager = new DBManager();
$db = $dbManager->ConnectDB();
$query = "select * from users";
$result = $db->query($query);
//print_r($result->fetch_array()["google_id"]);

require_once("class.chathandler.php");
$chatHandler = new ChatHandler($dbManager);

$socketResource = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($socketResource, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($socketResource, 0, PORT);
socket_listen($socketResource);

print("Socket Server started: <ws://" . HOST_NAME . ":" . PORT . ">\n");

$clientSocketArray = array($socketResource);

$clientIPArray = array();
while (true) {
    $newSocketArray = $clientSocketArray;
    socket_select($newSocketArray, $null, $null, 0, 10);

    if (in_array($socketResource, $newSocketArray)) {
        $newSocket = socket_accept($socketResource);
        $clientSocketArray[] = $newSocket;

        $header = socket_read($newSocket, 2048);
        $chatHandler->doHandshake($header, $newSocket, HOST_NAME, PORT);

//		socket_getpeername($newSocket, $client_ip_address);
		$client_ip_address = $chatHandler->get_client_ip($header);
		$clientIPArray[$client_ip_address] = $newSocket;

        $connectionACK = $chatHandler->newConnectionACK($client_ip_address);

//        $chatHandler->send($connectionACK);

        $newSocketIndex = array_search($socketResource, $newSocketArray);
        unset($newSocketArray[$newSocketIndex]);
    }

    foreach ($newSocketArray as $newSocketArrayResource) {
        while (@socket_recv($newSocketArrayResource, $socketData, 2048, 0) >= 1) {
            $socketMessage = $chatHandler->unseal($socketData);
            $messageObj = json_decode($socketMessage);
            
//			socket_getpeername($newSocketArrayResource, $client_ip_address);
			$client_ip_address = array_search($newSocketArrayResource, $clientIPArray);

            if (is_null($messageObj)) break 2;
            $chatHandler->parseChatBoxMessage($messageObj, $client_ip_address, $newSocketArrayResource);
//            $chat_box_message = @$chatHandler->createChatBoxMessage($messageObj->chat_user, $messageObj->chat_message, $client_ip_address);
//            $chatHandler->send($chat_box_message);
            break 2;
        }

        $socketData = @socket_read($newSocketArrayResource, 2048, PHP_NORMAL_READ);
        if ($socketData === false) {

//            socket_getpeername($newSocketArrayResource, $client_ip_address);
			$client_ip_address = array_search($newSocketArrayResource, $clientIPArray);

            $connectionACK = $chatHandler->connectionDisconnectACK($newSocketArrayResource, $client_ip_address);
//            $chatHandler->send($connectionACK);
            $newSocketIndex = array_search($newSocketArrayResource, $clientSocketArray);
            unset($clientSocketArray[$newSocketIndex]);
        }
    }
}
socket_close($socketResource);
$db->close();