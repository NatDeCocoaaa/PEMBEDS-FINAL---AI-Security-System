<?php
require_once "../config/database.php";
require_once "../models/User.php";

class UserController {
    public function index() {
        $database = new Database();
        $db = $database->connect();

        $user = new User($db);
        $stmt = $user->getAllUsers();

        include "../views/user_view.php";
    }
}
