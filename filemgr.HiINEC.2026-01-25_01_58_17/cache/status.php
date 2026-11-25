<?php
// Echo Cross
// Telegram: @tasjJson

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['online'=>0,'max'=>1000], JSON_UNESCAPED_UNICODE);
