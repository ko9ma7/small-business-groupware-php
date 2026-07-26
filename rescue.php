<?php
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
exit('사용하지 않는 경로입니다.');
