<?php
session_start();
unset($_SESSION['active_edit_id']);
http_response_code(204);
