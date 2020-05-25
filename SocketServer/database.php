<?php

class DBManager
{
    var $db;

    function connectDB()
    {
        $this->db = new mysqli(HOST_NAME, DB_USER, DB_PASSWORD, DB_NAME);
        if (mysqli_connect_errno()) {
            printf("Connect failed: %s\n", mysqli_connect_error());
            exit();
        }
        return $this->db;
    }

    function addPrinter($name, $password, $readonlypassword)
    { // name is same as email
        $origin = $name.$password;
        $token = md5($origin);
        $result = $this->db->query("select * from printers where email = '" . $name . "'");
        if ($result && $result->num_rows > 0) {
            $this->activePrinter($name, $password, $token, $readonlypassword);
        } else {
            $this->db->query("INSERT INTO `printers`(`name`, `email`, `password`, `status`, `token`, `readonlypassword`) VALUES ('" . $name . "', '" . $name . "', '" . $password . "', '" . "connected" . "', '".$token."', '".$readonlypassword."')");
        }
        return $token;
    }

    function activePrinter($name, $password, $token, $readonlypassword)
    {
        $this->db->query("UPDATE `printers` SET `password` = '" . $password . "', `status` = '" . "connected" . "', `token` = '".$token."', `readonlypassword` = '".$readonlypassword."' WHERE `email` = '" . $name . "'");
    }

    function inactivePrinter($name)
    {
        $this->db->query("UPDATE `printers` SET `status` = '" . "disconnected" . "' WHERE `email` = '" . $name . "'");
    }

    function checkAssign($user, $printer)
    {
        $result = $this->db->query("SELECT assigns.id, users.email as user, printers.email as printer, assigns.status as status FROM assigns 
                left join users on assigns.user = users.id 
                left join printers on assigns.printer = printers.id 
                where users.email = '" . $user . "' and printers.email = '" . $printer . "' and assigns.status = 'approved';");
        if ($result && $result->num_rows > 0) {
            return true;
        }
        return false;
    }

    function getAssignedUsers($printer){
        $users = array();
        $result = $this->db->query("SELECT users.email as user FROM assigns 
                left join users on assigns.user = users.id 
                left join printers on assigns.printer = printers.id 
                where printers.email = '" . $printer . "' and assigns.status = 'approved';");
        while($result && $row = $result->fetch_array()){
            $users[] = $row["user"];
        }
        return $users;
    }

    function savePrinterStatus($message){
        if($message->sender_type !== "printer"){
            return;
        }
        $result = $this->db->query("select id from printers where email = '".$message->sender_name."'");
        if(!$result || $result->num_rows == 0){
            return;
        }
        $printer_id = $result->fetch_array()["id"];
        if(isset($message->msg_content->button)){
            $button = $message->msg_content->button;
            $result = $this->db->query("select id from printer_metas where printer = '".$printer_id."' and meta_name = '".$button->name."'");
            if($result && $result->num_rows){
                $this->db->query("update printer_metas set meta_value = '".json_encode($button)."' where printer = '".$printer_id."' and meta_name = '".$button->name."'");
            }
            else{
                $this->db->query("insert into printer_metas(printer, meta_name, meta_value) values ('".$printer_id."', '".$button->name."', '".json_encode($button)."')");
            }
        }
        else if(isset($message->msg_content->property)){
            $property = $message->msg_content->property;
            $result = $this->db->query("select id from printer_metas where printer = '".$printer_id."' and meta_name = 'property'");
            if($result && $result->num_rows){
                $this->db->query("update printer_metas set meta_value = '".json_encode($property)."' where printer = '".$printer_id."' and meta_name = 'property'");
//                print_r("update printer_metas set meta_value = '".json_encode($property)."' where printer = '".$printer_id."' and meta_name = 'property'");
            }
            else{
                $this->db->query("insert into printer_metas(printer, meta_name, meta_value) values ('".$printer_id."', 'property', '".json_encode($property)."')");
//                print_r("insert into printer_metas(printer, meta_name, meta_value) values ('".$printer_id."', 'property', '".json_encode($property)."')");
            }
        }
    }
}