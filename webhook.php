<?php
// webhook.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

// 🔧 Логирование ошибок
function logError($message)
{
    $logFile = __DIR__ . '/bot_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function escapeMarkdown($text)
{
    $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    foreach ($chars as $char) {
        $text = str_replace($char, '\\' . $char, $text);
    }
    return $text;
}

function saveUser($user_id, $username, $pdo)
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (telegram_id, username, last_seen) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                username = VALUES(username),
                last_seen = NOW()
        ");
        $stmt->execute([$user_id, $username]);
    } catch (Exception $e) {
        logError("saveUser error: " . $e->getMessage());
    }
}


try {
    // Подключение к БД
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    logError("DB Connection Error: " . $e->getMessage());
    exit;
}

// Получаем update от Telegram
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) exit;

// Обработка
try {
    if (isset($update['message'])) {
        handleMessage($update['message'], $pdo);
    } elseif (isset($update['callback_query'])) {
        handleCallback($update['callback_query'], $pdo);
    }
} catch (Exception $e) {
    logError("Main handler error: " . $e->getMessage());
    if (isset($update['callback_query'])) {
        sendTelegramRequest('answerCallbackQuery', [
            'callback_query_id' => $update['callback_query']['id'],
            'text' => '❌ Ошибка сервера, попробуйте позже',
            'show_alert' => true
        ]);
    }
}

// ==================== ФУНКЦИИ ====================

function handleMessage($message, $pdo)
{
    try {
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $username = $message['from']['username'] ?? 'user_' . $user_id;
        $text = $message['text'] ?? '';

        saveUser($user_id, $username, $pdo);

        if (strpos($text, '/start') === 0) {
            showMenu($chat_id, $username);
        } elseif (strpos($text, '/book') === 0 || $text === '📅 Забронировать') {
            showBooking($chat_id, $user_id, $username, date('Y-m-d'), $pdo);
        } elseif (strpos($text, '/report') === 0 || $text === '📊 Отчет') {
            showReport($chat_id, $user_id, $pdo);
        } elseif (strpos($text, '/cancel') === 0 || $text === '❌ Отменить') {
            showCancel($chat_id, $user_id, $pdo);
        } else {
            showMenu($chat_id, $username);
        }
    } catch (Exception $e) {
        logError("handleMessage error: " . $e->getMessage());
    }
}

function handleCallback($callback, $pdo)
{
    try {
        $chat_id = $callback['message']['chat']['id'];
        $user_id = $callback['from']['id'];
        $username = $callback['from']['username'] ?? 'user_' . $user_id;
        $data = $callback['data'];
        $callback_id = $callback['id'];

        saveUser($user_id, $username, $pdo);

        sendTelegramRequest('answerCallbackQuery', [
            'callback_query_id' => $callback_id
        ]);

        if ($data === 'book') {
            showBooking($chat_id, $user_id, $username, date('Y-m-d'), $pdo);
        } elseif ($data === 'report') {
            showReport($chat_id, $user_id, $pdo);
        } elseif ($data === 'cancel') {
            showCancel($chat_id, $user_id, $pdo);
        } elseif (strpos($data, 'cancel_') === 0) {
            $booking_id = str_replace('cancel_', '', $data);
            cancelSpecificBooking($chat_id, $user_id, $booking_id, $pdo);
        } elseif ($data === 'back') {
            showMenu($chat_id, $username);
        } elseif (strpos($data, 'free_') === 0) {
            $parts = explode('_', $data, 3);
            if (count($parts) < 2) {
                throw new Exception("Invalid callback data: $data");
            }
            $time = $parts[1];
            $date = $parts[2] ?? date('Y-m-d');

            $today = date('Y-m-d');
            $tomorrow = date('Y-m-d', strtotime('+1 day'));

            if ($date !== $today && $date !== $tomorrow) {
                sendTelegramRequest('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "⛔ Можно бронировать только на сегодня или завтра"
                ]);
                logError("Invalid date booking attempt: user=$user_id, date=$date");
                return;
            }

            bookSlot($chat_id, $user_id, $username, $time, $date, $pdo);
        } elseif ($data === 'date_today') {
            showBooking($chat_id, $user_id, $username, date('Y-m-d'), $pdo);
        } elseif ($data === 'date_tomorrow') {
            // ✅ Проверка: можно ли бронировать завтра (только после 12:00)
            $currentHour = (int)date('H');
            if ($currentHour < 12) {
                sendTelegramRequest('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "⏰ Бронирование на завтра откроется в 12:00\n\nСейчас доступно только сегодня."
                ]);
                return;
            }
            showBooking($chat_id, $user_id, $username, date('Y-m-d', strtotime('+1 day')), $pdo);
        } elseif (strpos($data, 'busy_') === 0 || strpos($data, 'passed_') === 0) {
            sendTelegramRequest('answerCallbackQuery', [
                'callback_query_id' => $callback_id,
                'text' => '⛔ Это время недоступно!',
                'show_alert' => true
            ]);
        } elseif ($data === 'noop' || $data === 'timezone') {
            // Пустые кнопки
        } else {
            logError("Unknown callback: $data from user $user_id");
        }
    } catch (Exception $e) {
        logError("handleCallback error: " . $e->getMessage() . " | Data: " . ($data ?? 'N/A'));
        sendTelegramRequest('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text' => '❌ Произошла ошибка, попробуйте снова',
            'show_alert' => true
        ]);
    }
}

function cancelSpecificBooking($chat_id, $user_id, $booking_id, $pdo)
{
    try {
        $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = ? AND telegram_id = ? AND status IN ('active', 'completed')");
        $stmt->execute([$booking_id, $user_id]);

        if ($stmt->rowCount() > 0) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ Бронь отменена"
            ]);
            logError("Booking cancelled (deleted): id=$booking_id, user=$user_id");
        } else {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Не удалось отменить (бронь не найдена или уже отменена)"
            ]);
        }
    } catch (Exception $e) {
        logError("cancelSpecificBooking error: " . $e->getMessage());
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Ошибка при отмене"
        ]);
    }
}

function showMenu($chat_id, $username)
{
    try {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📅 Забронировать', 'callback_data' => 'book']],
                [['text' => '📊 Отчет', 'callback_data' => 'report']],
                [['text' => '❌ Отменить', 'callback_data' => 'cancel']],
            ]
        ];

        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "👋 Привет, @$username!\n\nВыберите действие:",
            'reply_markup' => json_encode($keyboard)
        ]);
    } catch (Exception $e) {
        logError("showMenu error: " . $e->getMessage());
    }
}

function showBooking($chat_id, $user_id, $username, $selected_date, $pdo)
{
    try {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $currentHour = (int)date('H');

        $date_label = ($selected_date === $today) ? 'Сегодня' : 'Завтра';

        $can_show_tomorrow = ($currentHour >= 12);

        $date_row = [
            ['text' => ($selected_date === $today ? '🔘 Сегодня' : '📅 Сегодня'), 'callback_data' => 'date_today'],
        ];

        // Кнопка "Завтра" (только после 12:00)
        if ($can_show_tomorrow) {
            $date_row[] = [
                'text' => ($selected_date === $tomorrow ? '🔘 Завтра' : '📅 Завтра'),
                'callback_data' => 'date_tomorrow'
            ];
        } else {
            $date_row[] = [
                'text' => '🔒 Завтра (с 12:00)',
                'callback_data' => 'noop'
            ];
        }

        $keyboard = ['inline_keyboard' => [$date_row]];
        $row = [];

        for ($hour = 0; $hour <= 23; $hour++) {
            $time = sprintf('%02d:00', $hour);

            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_date = ? AND time_slot = ? AND status IN ('active', 'completed')");
            $stmt->execute([$selected_date, $time]);
            $booked = $stmt->fetch();

            $is_passed = false;
            if ($selected_date === $today) {
                $currentHour = (int)date('H');
                $currentMinute = (int)date('i');

                // Строгая проверка: если минуты прошли — слот недоступен
                if ($hour < $currentHour) {
                    $is_passed = true;
                } elseif ($hour === $currentHour && $currentMinute > 0) {
                    $is_passed = true;  // ← Даже 1 минута делает слот прошедшим
                }
            }

            if ($is_passed) {
                $btn = ['text' => "⏰ $time", 'callback_data' => "passed_$time"];
            } elseif ($booked) {
                $btn = ['text' => "❌ $time", 'callback_data' => "busy_$time"];
            } else {
                $btn = ['text' => "✅ $time", 'callback_data' => "free_{$time}_{$selected_date}"];
            }

            $row[] = $btn;
            if (count($row) >= 4) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }

        $keyboard['inline_keyboard'][] = [['text' => '🔙 Назад', 'callback_data' => 'back']];

        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "📅 *Выберите время*\n📆 $date_label (" . date('d.m.Y', strtotime($selected_date)) . ")\n\n✅ — свободно\n❌ — занято\n⏰ — прошло",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    } catch (Exception $e) {
        logError("showBooking error: " . $e->getMessage());
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Ошибка при загрузке времени: " . $e->getMessage()
        ]);
    }
}

function bookSlot($chat_id, $user_id, $username, $time, $date, $pdo)
{
    try {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        if ($date !== $today && $date !== $tomorrow) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Можно бронировать только на сегодня или завтра"
            ]);
            return;
        }

        if ($date === $tomorrow) {
            $currentHour = (int)date('H');
            if ($currentHour < 12) {
                sendTelegramRequest('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "⏰ Бронирование на завтра откроется в 12:00"
                ]);
                return;
            }
        }

        if ($date === $today) {
            $currentHour = (int)date('H');
            $slotHour = (int)substr($time, 0, 2);
            if ($slotHour < $currentHour) {
                sendTelegramRequest('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "❌ Нельзя забронировать прошедшее время"
                ]);
                return;
            }
        }

        $stmt = $pdo->prepare("SELECT id FROM bookings WHERE booking_date = ? AND time_slot = ? AND status IN ('active', 'completed')");
        $stmt->execute([$date, $time]);
        if ($stmt->fetch()) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Это время только что заняли! Выберите другое."
            ]);
            logError("Double booking attempt: user=$user_id, date=$date, time=$time");
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO bookings (telegram_id, username, booking_date, time_slot, notified) VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([$user_id, $username, $date, $time]);

        $date_label = ($date === $today) ? 'Сегодня' : 'Завтра';

        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ *Забронировано!*\n\n👤 @$username\n⏰ $time\n📆 $date_label\n\n🔔 Вы получите уведомление когда время начнётся",
            'parse_mode' => 'Markdown'
        ]);

        logError("Booking created: user=$user_id, date=$date, time=$time");
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Это время только что заняли! Выберите другое."
            ]);
        } else {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Ошибка базы данных"
            ]);
            logError("bookSlot PDO error: " . $e->getMessage());
        }
    } catch (Exception $e) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Ошибка: " . $e->getMessage()
        ]);
        logError("bookSlot error: " . $e->getMessage());
    }
}

function showReport($chat_id, $user_id, $pdo)
{
    try {

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $stmt = $pdo->prepare("
            SELECT * FROM bookings
            WHERE booking_date IN (?, ?, ?)
            AND status IN ('active', 'completed')
            ORDER BY booking_date DESC, time_slot
        ");
        $stmt->execute([$yesterday, $today, $tomorrow]);
        $bookings = $stmt->fetchAll();

        if (empty($bookings)) {
            $text = "📊 Нет броней за последние " . REPORT_DAYS_LIMIT . " дня";
        } else {
            $text = "📊 *ОТЧЕТ*\n";
            $text .= "📅 Текущие " . REPORT_DAYS_LIMIT . " дня\n\n";
            $currentDate = '';

            foreach ($bookings as $b) {
                if ($b['booking_date'] !== $currentDate) {
                    $currentDate = $b['booking_date'];
                    $text .= "━━━━━━━━\n";
                    $text .= "📆 " . date('d.m.Y', strtotime($currentDate)) . "\n";
                }

                // ✅ Экранируем username (чтобы _ отображался корректно)
                $safe_username = escapeMarkdown($b['username']);
                // ✅ Экранируем username перед выводом
                $safe_username = escapeMarkdown($b['username']);

                $timeSlot = $b['time_slot'] ?? 'Не указано';
                $username = $safe_username ?? 'Аноним';

                if ($b['status'] === 'completed') {
                    $text .= "✅ $timeSlot — @$username\n";
                } else {
                    $text .= "⏰ $timeSlot — @$username\n";
                }
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Назад', 'callback_data' => 'back']]
            ]
        ];

        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    } catch (Exception $e) {
        logError("showReport error: " . $e->getMessage());
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Ошибка при загрузке отчета"
        ]);
    }
}

function showCancel($chat_id, $user_id, $pdo)
{
    try {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $stmt = $pdo->prepare("
            SELECT * FROM bookings
            WHERE telegram_id = ?
            AND booking_date IN (?, ?)
           AND status IN ('active', 'completed')
            ORDER BY booking_date, time_slot
        ");
        $stmt->execute([$user_id, $today, $tomorrow]);
        $bookings = $stmt->fetchAll();

        if (empty($bookings)) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ У вас нет активных броней на сегодня и завтра"
            ]);
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        $currentDate = '';

        foreach ($bookings as $b) {
            if ($b['booking_date'] !== $currentDate) {
                $currentDate = $b['booking_date'];
                $date_label = ($currentDate === $today) ? '📆 Сегодня' : '📆 Завтра';
                $keyboard['inline_keyboard'][] = [
                    ['text' => $date_label, 'callback_data' => 'noop']
                ];
            }
            $keyboard['inline_keyboard'][] = [
                ['text' => "❌ " . $b['time_slot'], 'callback_data' => "cancel_" . $b['id']]
            ];
        }

        $keyboard['inline_keyboard'][] = [['text' => '🔙 Назад', 'callback_data' => 'back']];

        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "⚠️ *Выберите бронь для отмены*",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    } catch (Exception $e) {
        logError("showCancel error: " . $e->getMessage());
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Ошибка: " . $e->getMessage()
        ]);
    }
}

function confirmCancel($chat_id, $user_id, $pdo)
{
    try {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE telegram_id = ? AND booking_date = ? AND AND status IN ('active', 'completed')");
        $stmt->execute([$user_id, $today]);

        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ Бронь отменена"
        ]);
    } catch (Exception $e) {
        logError("confirmCancel error: " . $e->getMessage());
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Ошибка при отмене"
        ]);
    }
}

// ❌ УДАЛЕНО: Очистка БД на каждый запрос (это вызывало блокировки!)
// Перенесено в отдельный файл cleanup.php для cron
