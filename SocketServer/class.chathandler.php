<?php
class ChatHandler {
    var $dbManager;
    var $printers;
    var $users;
    var $tokens;

    function ChatHandler($db){
        $this->dbManager = $db;
        $this->printers = array();
        $this->users = array();
        $this->tokens = array();
    }

	function get_client_ip($header){
		//$header = socket_read($socket, 2048);
		$ip = "";
		foreach(preg_split("/((\r?\n)|(\r\n?))/", $header) as $line){
			$line = str_replace(' ', '', $line);
			$nodes = explode(":", $line);
			if($nodes[0] == "X-Forwarded-For"){
				$ip = $nodes[1];
				break;
			}
		}
		return $ip;
	}

	function send($message) {
		global $clientSocketArray;
		$messageLength = strlen($message);
		foreach($clientSocketArray as $clientSocket)
		{
			@socket_write($clientSocket,$message,$messageLength);
		}
		return true;
	}

	function sendMessage($clientSocket, $messageJSON){
        $chatMessage = $this->seal(json_encode($messageJSON));
        $messageLength = strlen($chatMessage);
        @socket_write($clientSocket,$chatMessage,$messageLength);
    }


	function unseal($socketData) {
		$length = ord($socketData[1]) & 127;
		if($length == 126) {
			$masks = substr($socketData, 4, 4);
			$data = substr($socketData, 8);
		}
		elseif($length == 127) {
			$masks = substr($socketData, 10, 4);
			$data = substr($socketData, 14);
		}
		else {
			$masks = substr($socketData, 2, 4);
			$data = substr($socketData, 6);
		}
		$socketData = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$socketData .= $data[$i] ^ $masks[$i%4];
		}
		return $socketData;
	}

	function seal($socketData) {
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($socketData);
		
		if($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif($length > 125 && $length < 65536)
			$header = pack('CCn', $b1, 126, $length);
		elseif($length >= 65536)
			$header = pack('CCNN', $b1, 127, $length);
		return $header.$socketData;
	}

	function doHandshake($received_header,$client_socket_resource, $host_name, $port) {
		$headers = array();
		$lines = preg_split("/\r\n/", $received_header);
		foreach($lines as $line)
		{
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
			{
				$headers[$matches[1]] = $matches[2];
			}
		}

		$secKey = $headers['Sec-WebSocket-Key'];
		$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
		$buffer  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
		"Upgrade: websocket\r\n" .
		"Connection: Upgrade\r\n" .
		"WebSocket-Origin: $host_name\r\n" .
		"WebSocket-Location: ws://$host_name:$port/demo/shout.php\r\n".
		"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		socket_write($client_socket_resource,$buffer,strlen($buffer));
	}
	
	function newConnectionACK($client_ip_address) {
		$message = 'New client ' . $client_ip_address.' joined';
		$messageArray = array('message'=>$message,'message_type'=>'chat-connection-ack');
		$ACK = $this->seal(json_encode($messageArray));
//		$this->log($message, "Connect", $client_ip_address);
		return $ACK;
	}

    function log($message, $message_type, $sender_type, $sender_name, $client_ip_address){
        print(date("[Y-m-d H:i:s] ")."\t[".$message_type."]\t"."[".$sender_type."]\t"."[".$client_ip_address."]\t"."[".$sender_name."]\t".$message."\n");
        flush();
    }

    function createPrinterDisconnectMsg($printer){
        $msg = array();
        $msg["sender_type"] = "printer";
        $msg["sender_name"] = $printer;
        $msg["msg_type"] = "disconnect";
        return $msg;
    }

    function createPrinterTokenMsg($printer, $token){
        $msg = array();
        $msg["sender_type"] = "server";
        $msg["sender_name"] = "Server";
        $msg["msg_type"] = "token";
        $msg_content = array();
        $msg_content["printer_name"] = $printer;
        $msg_content["token"] = $token;
        $msg["msg_content"] = $msg_content;
        return $msg;
    }

    function createUserLogOutMsg($user){
        $msg = array();
        $msg["sender_type"] = "server";
        $msg["sender_name"] = "Server";
        $msg["msg_type"] = "command";
        $msg_content = array();
        $msg_content["command_name"] = "logout";
        $msg_content["user_name"] = $user;
        $msg["msg_content"] = $msg_content;
        return $msg;
    }

	function connectionDisconnectACK($newSocketArrayResource, $client_ip_address) {
        $user = array_search($newSocketArrayResource, $this->users);
        if($user){
            unset($this->users[$user]);
            $this->log("", "Disconnected", "user", $user, $client_ip_address);
        }
        $printer = array_search($newSocketArrayResource, $this->printers);
        if($printer){
            unset($this->printers[$printer]);
            $this->log("", "Disconnected", "printer", $printer, $client_ip_address);
            $users = $this->dbManager->getAssignedUsers($printer);
            foreach ($users as $user_mail){
                if(isset($this->users[$user_mail])){
                    $message_obj = $this->createPrinterDisconnectMsg($printer);
                    $this->sendMessage($this->users[$user_mail], $message_obj);
                }
            }
            $this->dbManager->inactivePrinter($printer);
        }

		$message = 'Client ' . $client_ip_address.' disconnected';
		$messageArray = array('message'=>$message,'message_type'=>'chat-connection-ack');
		$ACK = $this->seal(json_encode($messageArray));
//        $this->log($message, "Disconnect", $client_ip_address);
		return $ACK;
	}

	function parseChatBoxMessage($message_obj, $client_ip_address, $socket){
	    if($message_obj->sender_type == "printer"){
            if($message_obj->msg_type == "auth"){
                $token = $this->dbManager->addPrinter($message_obj->sender_name, $message_obj->msg_content->password, $message_obj->msg_content->ReadOnlyPassword);
                $this->tokens[$message_obj->sender_name] = $token;
                $msg_token_reply = $this->createPrinterTokenMsg($message_obj->sender_name, $token);
                $this->sendMessage($socket, $msg_token_reply);


                $this->printers[$message_obj->sender_name] = $socket;
                $this->log(json_encode($message_obj->msg_content), "Add", $message_obj->sender_type, $message_obj->sender_name, $client_ip_address);
                $users = $this->dbManager->getAssignedUsers($message_obj->sender_name);
                foreach ($users as $user_mail){
                    if(isset($this->users[$user_mail])){
                        unset($message_obj->msg_content);
                        $this->sendMessage($this->users[$user_mail], $message_obj);
                    }
                }
            }
            if($message_obj->msg_type == "status"){
                $this->log(json_encode($message_obj->msg_content), "Receive", $message_obj->sender_type, $message_obj->sender_name, $client_ip_address);
                if($this->tokens[$message_obj->sender_name] != $message_obj->msg_content->token){
                    print("Invalid token.\n");
                    flush();
                }
                else{
                    $users = $this->dbManager->getAssignedUsers($message_obj->sender_name);
                    foreach ($users as $user_mail){
                        if(isset($this->users[$user_mail])){
                            $this->sendMessage($this->users[$user_mail], $message_obj);
                        }
                    }
                    $this->dbManager->savePrinterStatus($message_obj);
                }
            }
        }
        else if($message_obj->sender_type == "user"){
            if($message_obj->msg_type == "auth"){
//                $this->dbManager->addPrinter($message_obj->sender_name, $message_obj->msg_content->password);
                if(isset($this->users[$message_obj->sender_name])){
                    $msg_logout = $this->createUserLogOutMsg($message_obj->sender_name);
                    $this->sendMessage($this->users[$message_obj->sender_name], $msg_logout);
                }
                $this->users[$message_obj->sender_name] = $socket;
                $this->log(json_encode($message_obj->msg_content), "Add", $message_obj->sender_type, $message_obj->sender_name, $client_ip_address);
            }
            if($message_obj->msg_type == "command"){
                $this->log(json_encode($message_obj->msg_content), "Receive", $message_obj->sender_type, $message_obj->sender_name, $client_ip_address);
                $printer_name = $message_obj->msg_content->printer_name;
                $user_name = $message_obj->sender_name;
                if(!$this->dbManager->checkAssign($user_name, $printer_name)) {
                    print("Not allowed action\n");
                    flush();
                }
                else if(!isset($this->printers[$printer_name])){
                    print("The printer is not available.\n");
                    flush();
                }
                else{
                    $this->sendMessage($this->printers[$printer_name], $message_obj);
//                    print("Sent a command to the printer.\n");
                }
            }
        }
    }
}
