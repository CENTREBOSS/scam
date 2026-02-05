<?php
// ====================================================================
// CONFIGURATION & ENVIRONMENT (SOZLAMALAR)
// ====================================================================
// Render Environment Variables orqali olinadi
$bot_token = getenv('BOT_TOKEN'); 
$admin_id = getenv('ADMIN_ID'); // Admin ID raqami (masalan: 123456789)

if (!$bot_token) {
    die("XATOLIK: BOT_TOKEN topilmadi! Render Environment sozlamalarini tekshiring.");
}

// ====================================================================
// DATABASE CONNECTION (MYSQL)
// ====================================================================
// Render-dagi Environment Variables-dan olinadi
$db_host = getenv('DB_HOST'); // Masalan: mysql-1234.aivencloud.com
$db_port = getenv('DB_PORT'); // Masalan: 3306
$db_name = getenv('DB_NAME'); // Masalan: defaultdb
$db_user = getenv('DB_USER'); // Masalan: avnadmin
$db_pass = getenv('DB_PASS'); // Parol

try {
    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Jadvallar (MySQL sintaksisiga moslangan)
    $pdo->exec("CREATE TABLE IF NOT EXISTS scams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        target_id VARCHAR(100),
        username VARCHAR(100),
        type VARCHAR(50),
        reason TEXT,
        photo_id TEXT,
        audio_id TEXT,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        chat_id BIGINT PRIMARY KEY,
        fullname VARCHAR(255),
        username VARCHAR(255),
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_states (
        user_id BIGINT PRIMARY KEY,
        state VARCHAR(50),
        data TEXT
    )");

} catch (PDOException $e) {
    error_log("Baza bilan xatolik: " . $e->getMessage());
    die("Baza bilan bog'lanishda xatolik yuz berdi.");
}

// ====================================================================
// TELEGRAM API FUNKSIYALARI
// ====================================================================
function bot($method, $datas = []) {
    global $bot_token;
    $url = "https://api.telegram.org/bot" . $bot_token . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("Curl xatosi: " . curl_error($ch));
    }
    curl_close($ch);
    return json_decode($res);
}

// ESKI HOLATI (Xatoli):
// $stmt = $pdo->prepare("INSERT OR REPLACE INTO admin_states (user_id, state, data) VALUES (:uid, :st, :dt)");

// YANGI HOLATI (To'g'risi):
function updateState($user_id, $state, $data = []) {
    global $pdo;
    $json_data = json_encode($data);
    // MySQL uchun REPLACE INTO ishlatamiz
    $stmt = $pdo->prepare("REPLACE INTO admin_states (user_id, state, data) VALUES (:uid, :st, :dt)");
    $stmt->execute([':uid' => $user_id, ':st' => $state, ':dt' => $json_data]);
}

function getState($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM admin_states WHERE user_id = :uid");
    $stmt->execute([':uid' => $user_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        return ['state' => $res['state'], 'data' => json_decode($res['data'], true)];
    }
    return ['state' => 'idle', 'data' => []];
}

function clearState($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM admin_states WHERE user_id = :uid");
    $stmt->execute([':uid' => $user_id]);
}

// ====================================================================
// ASOSIY MANTIQ
// ====================================================================
$update = json_decode(file_get_contents('php://input'));

if (isset($update->message)) {
    $message = $update->message;
    $chat_id = $message->chat->id;
    $text = isset($message->text) ? $message->text : "";
    $user_id = $message->from->id;
    $first_name = $message->from->first_name ?? 'User';
    $username_user = $message->from->username ?? '';

    // ----------------------------------------------------------------
    // 1. YANGI USERNI ANIQLASH VA BAZAGA QO'SHISH
    // ----------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT chat_id FROM users WHERE chat_id = :cid");
    $stmt->execute([':cid' => $chat_id]);
    $user_exists = $stmt->fetch();

    if (!$user_exists) {
        // Bazaga qo'shish
        $stmt_add = $pdo->prepare("INSERT INTO users (chat_id, fullname, username) VALUES (:cid, :fn, :un)");
        $stmt_add->execute([':cid' => $chat_id, ':fn' => $first_name, ':un' => $username_user]);

        // ADMINGA XABAR YUBORISH (Lichka linki bilan)
        $user_link = $username_user ? "@$username_user" : "<a href='tg://user?id=$chat_id'>$first_name</a>";
        
        bot('sendMessage', [
            'chat_id' => $admin_id,
            'text' => "🆕 **Yangi foydalanuvchi qo'shildi!**\n\n👤 Ism: $first_name\n🆔 ID: `$chat_id`\n🔗 Link: $user_link",
            'parse_mode' => 'HTML'
        ]);
    }

    // ----------------------------------------------------------------
    // 2. ADMIN BOSHQARUV PANELI
    // ----------------------------------------------------------------
    $is_admin = ($user_id == $admin_id);
    $state_info = getState($user_id);
    $state = $state_info['state'];
    $temp_data = $state_info['data'];

    if ($is_admin) {
        
        // Bekor qilish komandasi
        if ($text == '/cancel' || $text == "❌ Bekor qilish") {
            clearState($user_id);
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🛑 Jarayon bekor qilindi.",
                'reply_markup' => json_encode([
                    'keyboard' => [[['text' => '/admin']]], 
                    'resize_keyboard' => true
                ])
            ]);
            exit;
        }

        // Admin panelga kirish
        if ($text == '/admin') {
            clearState($user_id);
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "😎 **Admin Panelga Xush kelibsiz!**\n\nKerakli bo'limni tanlang:",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => "➕ Scam Qo'shish"], ['text' => "📢 Xabar tarqatish"]],
                        [['text' => "📊 Statistika"], ['text' => "/start"]]
                    ],
                    'resize_keyboard' => true
                ])
            ]);
            exit;
        }

        // [SCAM QO'SHISH] - 1-qadam: Turni tanlash
        if ($text == "➕ Scam Qo'shish") {
            updateState($user_id, 'step_1_type');
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📂 **Scam turini tanlang:**",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '🤖 Bot'], ['text' => '👤 Admin/User']],
                        [['text' => '📢 Kanal'], ['text' => '👥 Guruh']],
                        [['text' => '❌ Bekor qilish']]
                    ],
                    'resize_keyboard' => true
                ])
            ]);
            exit;
        }

        // [SCAM QO'SHISH] - 2-qadam: ID yoki Username so'rash
        if ($state == 'step_1_type') {
            $valid_types = ['🤖 Bot', '👤 Admin/User', '📢 Kanal', '👥 Guruh'];
            if (in_array($text, $valid_types)) {
                $temp_data['type'] = $text;
                updateState($user_id, 'step_2_target', $temp_data);
                bot('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "✍️ **Firibgarning ID raqami yoki Username-ni yozing:**\n(Masalan: `@scammer_bot` yoki `12345678`)",
                    'reply_markup' => json_encode(['keyboard' => [[['text' => '❌ Bekor qilish']]], 'resize_keyboard' => true])
                ]);
            } else {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Iltimos, menyudagi tugmalardan birini tanlang."]);
            }
            exit;
        }

        // [SCAM QO'SHISH] - 3-qadam: Rasm so'rash
        if ($state == 'step_2_target') {
            $target = str_replace(' ', '', $text); // Bo'sh joylarni olib tashlash
            $temp_data['target'] = $target;
            updateState($user_id, 'step_3_photo', $temp_data);
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📸 **Isbot uchun RASM yuboring.**\n\nAgar rasm bo'lmasa, pastdagi 'Mavjud emas' tugmasini bosing.",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => "🚫 Rasm mavjud emas"]],
                        [['text' => '❌ Bekor qilish']]
                    ],
                    'resize_keyboard' => true
                ])
            ]);
            exit;
        }

        // [SCAM QO'SHISH] - 4-qadam: Audio so'rash
        if ($state == 'step_3_photo') {
            if (isset($message->photo)) {
                // Eng sifatli rasmni olamiz
                $photo_id = end($message->photo)->file_id;
                $temp_data['photo'] = $photo_id;
            } elseif ($text == "🚫 Rasm mavjud emas") {
                $temp_data['photo'] = null;
            } else {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Rasm yuboring yoki 'Mavjud emas' tugmasini bosing."]);
                exit;
            }
            
            updateState($user_id, 'step_4_audio', $temp_data);
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🎤 **Qo'shimcha AUDIO yoki OVOZLI XABAR yuboring.**\n\nAgar audio isbot bo'lmasa, pastdagi tugmani bosing.",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => "🚫 Audio mavjud emas"]],
                        [['text' => '❌ Bekor qilish']]
                    ],
                    'resize_keyboard' => true
                ])
            ]);
            exit;
        }

        // [SCAM QO'SHISH] - 5-qadam: Sabab so'rash
        if ($state == 'step_4_audio') {
            if (isset($message->voice) || isset($message->audio)) {
                $audio_id = isset($message->voice) ? $message->voice->file_id : $message->audio->file_id;
                $temp_data['audio'] = $audio_id;
            } elseif ($text == "🚫 Audio mavjud emas") {
                $temp_data['audio'] = null;
            } else {
                bot('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Audio yuboring yoki 'Mavjud emas' tugmasini bosing."]);
                exit;
            }

            updateState($user_id, 'step_5_reason', $temp_data);
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "📝 **Firibgarlik sababini batafsil yozing:**\n(Bu matn userlarga ko'rinadi)",
                'reply_markup' => json_encode(['remove_keyboard' => true])
            ]);
            exit;
        }

        // [SCAM QO'SHISH] - 6-qadam: Bazaga saqlash
        if ($state == 'step_5_reason') {
            $reason = $text;
            $target = $temp_data['target'];
            
            // Username va ID ni ajratish
            $username = null;
            $target_id = null;

            if (strpos($target, '@') !== false) {
                $username = str_replace('@', '', $target);
            } elseif (is_numeric($target)) {
                $target_id = $target;
            } else {
                $username = $target; // Agar shunchaki so'z bo'lsa username deb olamiz
            }

            // INSERT
            $stmt = $pdo->prepare("INSERT INTO scams (target_id, username, type, reason, photo_id, audio_id) VALUES (:tid, :usr, :typ, :rea, :pho, :aud)");
            $stmt->execute([
                ':tid' => $target_id,
                ':usr' => $username,
                ':typ' => $temp_data['type'],
                ':rea' => $reason,
                ':pho' => $temp_data['photo'],
                ':aud' => $temp_data['audio']
            ]);

            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ **Qora ro'yxatga muvaffaqiyatli qo'shildi!**\n\nBaza yangilandi. Yana /admin buyrug'i orqali davom eting.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['keyboard' => [[['text' => '/admin']]], 'resize_keyboard' => true])
            ]);
            clearState($user_id);
            exit;
        }

        // [BROADCAST] - Xabar tarqatish
        if ($text == "📢 Xabar tarqatish") {
            updateState($user_id, 'broadcast_msg');
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✍️ Tarqatmoqchi bo'lgan xabaringizni yuboring (Matn, Rasm, Video yoki Forward):", 'reply_markup' => json_encode(['keyboard' => [[['text' => '❌ Bekor qilish']]], 'resize_keyboard' => true])]);
            exit;
        }

        if ($state == 'broadcast_msg') {
            $users = $pdo->query("SELECT chat_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $total = count($users);
            $sent = 0;
            
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🚀 Xabar tarqatish boshlandi... Jami userlar: $total"]);

            foreach ($users as $uid) {
                $res = bot('copyMessage', [
                    'chat_id' => $uid, 
                    'from_chat_id' => $chat_id, 
                    'message_id' => $message->message_id
                ]);
                if ($res && $res->ok) {
                    $sent++;
                }
            }
            
            bot('sendMessage', [
                'chat_id' => $chat_id, 
                'text' => "✅ **Tarqatish yakunlandi!**\n\nJami urinish: $total\nMuvaffaqiyatli: $sent",
                'reply_markup' => json_encode(['keyboard' => [[['text' => '/admin']]], 'resize_keyboard' => true])
            ]);
            clearState($user_id);
            exit;
        }
        
        // [STATISTIKA]
        if ($text == "📊 Statistika") {
            $user_count = $pdo->query("SELECT count(*) FROM users")->fetchColumn();
            $scam_count = $pdo->query("SELECT count(*) FROM scams")->fetchColumn();
            bot('sendMessage', [
                'chat_id' => $chat_id, 
                'text' => "📊 **Bot Statistikasi:**\n\n👥 Foydalanuvchilar: **$user_count** ta\n🚫 Aniqlangan scamlar: **$scam_count** ta",
                'parse_mode' => 'Markdown'
            ]);
            exit;
        }
    }

    // ----------------------------------------------------------------
    // 3. ODDIY FOYDALANUVCHI (USER) LOGIKASI
    // ----------------------------------------------------------------

    if ($text == '/start' || $text == "🔙 Bosh menyu") {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "Nimani tekshiramiz? Menyudan tanlang yoki shubhali ID/Username ni yozib yuboring.",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => "🛡 Scam Botlar"], ['text' => "👤 Scam Adminlar"]],
                    [['text' => "📢 Scam Kanallar"], ['text' => "👥 Scam Guruhlar"]]
                ],
                'resize_keyboard' => true
            ])
        ]);
        exit;
    }

    // Ro'yxatni ko'rish (Menyular)
    $cat_map = [
        "🛡 Scam Botlar" => '🤖 Bot',
        "👤 Scam Adminlar" => '👤 Admin/User',
        "📢 Scam Kanallar" => '📢 Kanal',
        "👥 Scam Guruhlar" => '👥 Guruh'
    ];

    if (array_key_exists($text, $cat_map)) {
        $type = $cat_map[$text];
        // Oxirgi 15 tasini chiqaramiz
        $stmt = $pdo->prepare("SELECT username, target_id, reason FROM scams WHERE type = :typ ORDER BY id DESC LIMIT 15");
        $stmt->execute([':typ' => $type]);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$list) {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Hozircha bu turdagi firibgarlar bazada yo'q."]);
        } else {
            $msg = "🚫 **Qora ro'yxat ($text):**\n\n";
            foreach ($list as $item) {
                $target = $item['username'] ? "@".$item['username'] : "ID: `".$item['target_id']."`";
                $msg .= "🔻 $target\n";
            }
            $msg .= "\n⚠️ _Batafsil ma'lumot olish uchun ID yoki Username ni menga yuboring._";
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'Markdown']);
        }
        exit;
    }

    // ----------------------------------------------------------------
    // 4. QIDIRUV (SEARCH) VA REDIRECT LOGIKASI
    // ----------------------------------------------------------------
    
    // Matnni tozalash (@ belgisini olib tashlash)
    $clean_text = str_replace(['@', ' '], '', $text);

    // Bazadan qidirish
    $stmt = $pdo->prepare("SELECT * FROM scams WHERE username = :inp OR target_id = :inp");
    $stmt->execute([':inp' => $clean_text]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        // --- TOPILDI (SCAM) ---
        $caption = "🚨 **DIQQAT! BU FIRIBGAR!** 🚨\n\n";
        $caption .= "👤 **Turi:** " . $result['type'] . "\n";
        $caption .= "🆔 **ID:** `" . ($result['target_id'] ?? 'Noma\'lum') . "`\n";
        $caption .= "📛 **Username:** @" . ($result['username'] ?? 'Mavjud emas') . "\n";
        $caption .= "📝 **Sabab:** \n" . $result['reason'] . "\n\n";
        $caption .= "⛔️ __Ushbu foydalanuvchi bilan har qanday savdoni to'xtating!__";

        // Rasm bormi?
        if ($result['photo_id']) {
            bot('sendPhoto', [
                'chat_id' => $chat_id,
                'photo' => $result['photo_id'],
                'caption' => $caption,
                'parse_mode' => 'Markdown'
            ]);
        } else {
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $caption,
                'parse_mode' => 'Markdown'
            ]);
        }

        // Audio bormi?
        if ($result['audio_id']) {
            bot('sendVoice', [
                'chat_id' => $chat_id,
                'voice' => $result['audio_id'],
                'caption' => "🎙 Qo'shimcha audio isbot"
            ]);
        }

    } else {
        // --- TOPILMADI (TOZA YOKI NOANIQ) ---
        
        // 1. Userga javob
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ **Bazada topilmadi.**\n\nUshbu ID/Username bizning qora ro'yxatda yo'q.\n\n🧐 _Lekin xavfsizlik uchun, so'rovingiz adminga tekshirish uchun yuborildi._",
            'parse_mode' => 'Markdown'
        ]);

        // 2. Adminga forward qilish (REDIRECT)
        if ($user_id != $admin_id) { // Admin o'zi qidirsa o'ziga jonatmaslik uchun
            $user_link = $username_user ? "@$username_user" : "ID: $user_id";
            
            bot('sendMessage', [
                'chat_id' => $admin_id,
                'text' => "🔍 **Yangi shubhali so'rov!**\n\nKim qidirdi: $user_link ($first_name)\nQidirilgan so'z: `$text`\n\n⚠️ _Tekshirib ko'ring, agar scam bo'lsa bazaga qo'shing._",
                'parse_mode' => 'Markdown'
            ]);
        }
    }
}
?>
