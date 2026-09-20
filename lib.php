<?php
declare(strict_types=1);

session_name('daypilot_session');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'path' => '/',
]);
session_start();

$config = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) { $local = require __DIR__ . '/config.local.php'; if (is_array($local)) { $config = array_replace_recursive($config, $local); } }
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kolkata');

function cfg(string $key, mixed $default = null): mixed {
    global $config;
    $segments = explode('.', $key);
    $v = $config;
    foreach ($segments as $segment) {
        if (!is_array($v) || !array_key_exists($segment, $v)) return $default;
        $v = $v[$segment];
    }
    return $v;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', cfg('db.host'), (int)cfg('db.port'), cfg('db.name'), cfg('db.charset'));
    $pdo = new PDO($dsn, (string)cfg('db.user'), (string)cfg('db.pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function now(): string { return date('Y-m-d H:i:s'); }

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function input_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(['error' => 'Invalid JSON body'], 400);
    return $data;
}

function user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT id,email,name,timezone,created_at,updated_at FROM users WHERE id=?');
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}

function require_user(): array {
    $u = user();
    if (!$u) json_response(['error' => 'Authentication required'], 401);
    return $u;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function require_csrf(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $provided)) json_response(['error' => 'Invalid CSRF token'], 419);
    }
}

function log_activity(string $action, ?string $type = null, ?string $id = null, array $meta = []): void {
    $u = user(); if (!$u) return;
    $st = db()->prepare('INSERT INTO activity_log(user_id,action,entity_type,entity_id,metadata,created_at) VALUES(?,?,?,?,?,?)');
    $st->execute([$u['id'],$action,$type,$id,json_encode($meta),now()]);
}

function normalize_priority(string $p): string {
    return in_array($p, ['low','medium','high'], true) ? $p : 'medium';
}

function clean_text(string $s, int $max = 255): string {
    $s = trim($s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

function fetch_tasks(string $userId, ?string $status = null, int $limit = 200): array {
    $sql = 'SELECT id,title,description,status,priority,due_at,start_at,end_at,estimated_minutes,completed_at,created_at,updated_at FROM tasks WHERE user_id=?';
    $args = [$userId];
    if ($status && in_array($status, ['open','done','archived'], true)) { $sql .= ' AND status=?'; $args[]=$status; }
    $sql .= ' ORDER BY status="done", COALESCE(start_at,due_at) IS NULL, COALESCE(start_at,due_at), FIELD(priority,"high","medium","low"), updated_at DESC LIMIT ' . (int)$limit;
    $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll();
}

function fetch_events(string $userId, string $from, string $to): array {
    $st = db()->prepare('SELECT id,title,description,location,start_at,end_at,source,external_id,created_at,updated_at FROM events WHERE user_id=? AND start_at < ? AND end_at > ? ORDER BY start_at');
    $st->execute([$userId,$to,$from]); return $st->fetchAll();
}

function fetch_notes(string $userId): array {
    $st = db()->prepare('SELECT id,title,content,tags,source,created_at,updated_at FROM notes WHERE user_id=? ORDER BY updated_at DESC LIMIT 100');
    $st->execute([$userId]); return $st->fetchAll();
}

function date_sql(?string $value): ?string {
    if ($value === null || $value === '') return null;
    $ts = strtotime($value);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

function plan_day(string $userId, string $date): array {
    $tasks = fetch_tasks($userId, 'open', 100);
    $dayStart = strtotime($date . ' 09:00:00');
    $dayEnd = strtotime($date . ' 20:00:00');
    $events = fetch_events($userId, $date . ' 00:00:00', $date . ' 23:59:59');
    $busy = [];
    foreach ($events as $event) {
        $busy[] = [strtotime($event['start_at']), strtotime($event['end_at'])];
    }
    usort($busy, fn($a,$b)=>$a[0]<=>$b[0]);
    $cursor = $dayStart;
    $blocks = [];
    $pdo = db();
    foreach ($tasks as &$task) {
        $urgency = 0;
        if ($task['due_at'] && date('Y-m-d', strtotime($task['due_at'])) < $date) $urgency = 3;
        elseif ($task['due_at'] && date('Y-m-d', strtotime($task['due_at'])) === $date) $urgency = 2;
        elseif ($task['due_at'] && strtotime($task['due_at']) < strtotime($date . ' +2 days')) $urgency = 1;
        $task['_score'] = (match($task['priority']) { 'high'=>30, 'medium'=>20, default=>10 }) + $urgency*10 + ($task['due_at'] ? 5 : 0);
    }
    unset($task);
    usort($tasks, fn($a,$b)=> ($b['_score']??0) <=> ($a['_score']??0));
    foreach ($tasks as $task) {
        $mins = max(15, min(180, (int)($task['estimated_minutes'] ?? 30)));
        $duration = $mins * 60;
        $placed = false;
        for ($attempt=0; $attempt<30 && $cursor+$duration <= $dayEnd; $attempt++) {
            $collision = null;
            foreach ($busy as $interval) {
                if ($cursor < $interval[1] && ($cursor+$duration) > $interval[0]) { $collision = $interval; break; }
            }
            if ($collision) { $cursor = $collision[1] + 10*60; continue; }
            $start = $cursor; $end = $cursor + $duration;
            $blocks[] = ['task_id'=>$task['id'],'title'=>$task['title'],'start_at'=>date('Y-m-d H:i:s',$start),'end_at'=>date('Y-m-d H:i:s',$end),'minutes'=>$mins];
            $st = $pdo->prepare('UPDATE tasks SET start_at=?, end_at=?, updated_at=? WHERE id=? AND user_id=? AND status="open"');
            $st->execute([date('Y-m-d H:i:s',$start),date('Y-m-d H:i:s',$end),now(),$task['id'],$userId]);
            $busy[] = [$start,$end];
            usort($busy, fn($a,$b)=>$a[0]<=>$b[0]);
            $cursor = $end + 10*60;
            $placed = true;
            break;
        }
        if (!$placed) break;
    }
    return $blocks;
}

function http_json(string $url, array $headers, array $body, int $timeout = 45): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $err) throw new RuntimeException('Upstream request failed: ' . $err);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Upstream returned invalid JSON (HTTP ' . $status . ')');
    if ($status >= 400) throw new RuntimeException((string)($data['error']['message'] ?? 'AI provider returned HTTP ' . $status));
    return $data;
}

function gemini_tools(): array {
    $taskEnum = ['low','medium','high'];
    return [
        ['type'=>'function','name'=>'create_task','description'=>'Create a personal work task.','parameters'=>['type'=>'object','properties'=>[
            'title'=>['type'=>'string','description'=>'Clear task title'],
            'priority'=>['type'=>'string','enum'=>$taskEnum],
            'due_at'=>['type'=>['string','null'],'description'=>'ISO date/time if a deadline is known'],
            'estimated_minutes'=>['type'=>['integer','null'],'description'=>'Estimated effort in minutes'],
            'description'=>['type'=>['string','null'],'description'=>'Optional task details']
        ],'required'=>['title','priority']]],
        ['type'=>'function','name'=>'list_tasks','description'=>'List the user’s current tasks.','parameters'=>['type'=>'object','properties'=>[
            'status'=>['type'=>'string','enum'=>['open','done','all']], 'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50]
        ]]],
        ['type'=>'function','name'=>'complete_task','description'=>'Mark an existing task complete.','parameters'=>['type'=>'object','properties'=>['task_id'=>['type'=>'string']],'required'=>['task_id']]],
        ['type'=>'function','name'=>'schedule_task','description'=>'Schedule a task in the user calendar by setting start and end times.','parameters'=>['type'=>'object','properties'=>[
            'task_id'=>['type'=>'string'], 'start_at'=>['type'=>'string'], 'end_at'=>['type'=>'string']
        ],'required'=>['task_id','start_at','end_at']]],
        ['type'=>'function','name'=>'create_event','description'=>'Create a local calendar event.','parameters'=>['type'=>'object','properties'=>[
            'title'=>['type'=>'string'], 'start_at'=>['type'=>'string'], 'end_at'=>['type'=>'string'], 'description'=>['type'=>['string','null']], 'location'=>['type'=>['string','null']]
        ],'required'=>['title','start_at','end_at']]],
        ['type'=>'function','name'=>'plan_today','description'=>'Build an optimized focus plan for the given date.','parameters'=>['type'=>'object','properties'=>['date'=>['type'=>'string','description'=>'YYYY-MM-DD']],'required'=>['date']]],
        ['type'=>'function','name'=>'get_analytics','description'=>'Get a concise work analytics summary.','parameters'=>['type'=>'object','properties'=>[]]],
        ['type'=>'function','name'=>'create_note','description'=>'Create a note in the user workspace.','parameters'=>['type'=>'object','properties'=>['title'=>['type'=>'string'],'content'=>['type'=>'string'],'tags'=>['type'=>['string','null']]],'required'=>['title','content']]],
    ];
}

function execute_ai_tool(string $name, array $args, array $u): array {
    $pdo = db(); $uid = $u['id'];
    switch ($name) {
        case 'create_task':
            $id = uuid(); $t = now();
            $st = $pdo->prepare('INSERT INTO tasks(id,user_id,title,description,priority,due_at,estimated_minutes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$id,$uid,clean_text((string)($args['title']??'')),($args['description']??null),normalize_priority((string)($args['priority']??'medium')),date_sql($args['due_at']??null),isset($args['estimated_minutes'])?(int)$args['estimated_minutes']:null,$t,$t]);
            log_activity('ai_create_task','task',$id); return ['ok'=>true,'task_id'=>$id];
        case 'list_tasks':
            $status = (string)($args['status']??'open'); $tasks = fetch_tasks($uid,$status==='all'?null:$status,max(1,min(50,(int)($args['limit']??20))));
            return ['tasks'=>$tasks];
        case 'complete_task':
            $st=$pdo->prepare('UPDATE tasks SET status="done",completed_at=?,updated_at=? WHERE id=? AND user_id=?'); $st->execute([now(),now(),(string)$args['task_id'],$uid]);
            if ($st->rowCount()===0) return ['ok'=>false,'error'=>'Task not found']; log_activity('ai_complete_task','task',(string)$args['task_id']); return ['ok'=>true];
        case 'schedule_task':
            $st=$pdo->prepare('UPDATE tasks SET start_at=?,end_at=?,updated_at=? WHERE id=? AND user_id=?'); $st->execute([date_sql($args['start_at']??''),date_sql($args['end_at']??''),now(),(string)$args['task_id'],$uid]);
            if ($st->rowCount()===0) return ['ok'=>false,'error'=>'Task not found']; log_activity('ai_schedule_task','task',(string)$args['task_id']); return ['ok'=>true];
        case 'create_event':
            $id=uuid(); $t=now(); $st=$pdo->prepare('INSERT INTO events(id,user_id,title,description,location,start_at,end_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$id,$uid,clean_text((string)$args['title']),($args['description']??null),($args['location']??null),date_sql($args['start_at']??''),date_sql($args['end_at']??''),$t,$t]);
            log_activity('ai_create_event','event',$id); return ['ok'=>true,'event_id'=>$id];
        case 'plan_today':
            $date = preg_replace('/[^0-9-]/','',(string)($args['date']??date('Y-m-d'))); $blocks=plan_day($uid,$date); log_activity('ai_plan_day'); return ['date'=>$date,'blocks'=>$blocks];
        case 'get_analytics':
            return analytics_data($uid, 30);
        case 'create_note':
            $id=uuid();$t=now();$st=$pdo->prepare('INSERT INTO notes(id,user_id,title,content,tags,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');$st->execute([$id,$uid,clean_text((string)$args['title']), (string)$args['content'], ($args['tags']??null),'ai',$t,$t]); log_activity('ai_create_note','note',$id); return ['ok'=>true,'note_id'=>$id];
        default: return ['ok'=>false,'error'=>'Unknown tool: '.$name];
    }
}

function gemini_chat(string $message, array $u): array {
    $key = (string)cfg('ai.api_key');
    if ($key === '') return ['text'=>'AI is not configured yet. Add GEMINI_API_KEY on the server to enable the full DayPilot assistant.','tool_actions'=>[]];
    $model = (string)cfg('ai.model','gemini-3.8-flash');
    $today = date('Y-m-d H:i:s');
    $tasks = fetch_tasks($u['id'], 'open', 30);
    $events = fetch_events($u['id'], date('Y-m-d 00:00:00'), date('Y-m-d H:i:s', strtotime('+7 days')));
    $notes = fetch_notes($u['id']);
    $context = json_encode(['user'=>['name'=>$u['name'],'timezone'=>$u['timezone']], 'now'=>$today,'tasks'=>$tasks,'events'=>$events,'notes'=>array_slice($notes,0,10)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $system = "You are DayPilot, a practical personal work assistant. Be concise, specific and action-oriented. You have tools that can change the user's workspace. Use tools for task/calendar/plan operations instead of pretending. Never claim an action succeeded unless the tool result says it succeeded. For risky external actions (email sending, destructive deletion) there are no tools, so never imply you sent something. Use the user's timezone. Break vague work into sensible steps. Current workspace context: {$context}";
    $pdo=db(); $st=$pdo->prepare('SELECT id,gemini_interaction_id FROM ai_threads WHERE user_id=?');$st->execute([$u['id']]);$thread=$st->fetch();
    $tools=gemini_tools();
    $input=[['type'=>'user_input','content'=>[['type'=>'text','text'=>$system."\n\nUser request: ".$message]]]];
    $previous=$thread['gemini_interaction_id']??null; $actions=[]; $lastText='';
    for ($round=0;$round<4;$round++) {
        $body = [
    'model' => $model,
    'input' => $input,
    'tools' => $tools
];

if ($previous) {
    $body['previous_interaction_id'] = $previous;
}
        $resp=http_json('https://generativelanguage.googleapis.com/v1beta/interactions',["Content-Type: application/json","x-goog-api-key: {$key}"],$body,60);
        $previous=(string)($resp['id']??$previous);
        $functionResults=[]; $hasCall=false;
        foreach (($resp['steps']??[]) as $step) {
            if (($step['type']??'')==='model_output') foreach (($step['content']??[]) as $part) if (isset($part['text'])) $lastText.=$part['text'];
            if (($step['type']??'')==='function_call') {
                $hasCall=true; $fn=(string)$step['name'];$args=is_array($step['arguments']??null)?$step['arguments']:[];
                $result=execute_ai_tool($fn,$args,$u); $actions[]=['tool'=>$fn,'args'=>$args,'result'=>$result];
                $functionResults[]=['type'=>'function_result','name'=>$fn,'call_id'=>(string)($step['id']??''),'result'=>[['type'=>'text','text'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]];
            }
        }
        $st=$pdo->prepare('INSERT INTO ai_threads(id,user_id,gemini_interaction_id,updated_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE gemini_interaction_id=VALUES(gemini_interaction_id),updated_at=VALUES(updated_at)');
        $st->execute([$thread['id']??uuid(),$u['id'],$previous,now()]);
        if (!$hasCall) break;
        $input=$functionResults;
    }
    return ['text'=>trim($lastText) ?: 'Done.','tool_actions'=>$actions];
}

function gemini_text(string $prompt, array $u): string {
    $key=(string)cfg('ai.api_key');
    if($key==='') throw new RuntimeException('AI is not configured.');
    $model=(string)cfg('ai.model','gemini-3.8-flash');
    $body=['model'=>$model,'input'=>$prompt,'store'=>false];
    $resp=http_json('https://generativelanguage.googleapis.com/v1beta/interactions',["Content-Type: application/json","x-goog-api-key: {$key}"],$body,60);
    foreach(($resp['steps']??[]) as $step){if(($step['type']??'')==='model_output'){foreach(($step['content']??[]) as $part){if(isset($part['text']))return trim((string)$part['text']);}}}
    return '';
}

function analytics_data(string $uid, int $days = 30): array {
    $pdo=db();
    $st=$pdo->prepare('SELECT COUNT(*) c, SUM(status="done") done, SUM(status="open") open, SUM(CASE WHEN status="open" AND due_at < NOW() THEN 1 ELSE 0 END) overdue, SUM(CASE WHEN status="done" THEN COALESCE(estimated_minutes,0) ELSE 0 END) focus_minutes FROM tasks WHERE user_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL ? DAY)');
    $st->execute([$uid,$days]); $summary=$st->fetch() ?: [];
    $daily=$pdo->prepare('SELECT DATE(completed_at) day, COUNT(*) completed, SUM(COALESCE(estimated_minutes,0)) minutes FROM tasks WHERE user_id=? AND status="done" AND completed_at>=DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY DATE(completed_at) ORDER BY day');
    $daily->execute([$uid,$days]);
    $byPriority=$pdo->prepare('SELECT priority, COUNT(*) total, SUM(status="done") done FROM tasks WHERE user_id=? GROUP BY priority ORDER BY FIELD(priority,"high","medium","low")');$byPriority->execute([$uid]);
    $completion = ((int)($summary['c']??0))>0 ? round(((int)($summary['done']??0)/(int)$summary['c'])*100,1) : 0;
    return ['days'=>$days,'summary'=>['total'=>(int)($summary['c']??0),'done'=>(int)($summary['done']??0),'open'=>(int)($summary['open']??0),'overdue'=>(int)($summary['overdue']??0),'focus_minutes'=>(int)($summary['focus_minutes']??0),'completion_rate'=>$completion],'daily'=>$daily->fetchAll(),'by_priority'=>$byPriority->fetchAll()];
}
