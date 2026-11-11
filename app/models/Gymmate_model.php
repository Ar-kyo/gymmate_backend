<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Gymmate_model extends Model
{
    private $db;

    public function __construct()
    {
        parent::__construct();
    }

    private function connection()
    {
        if ($this->db === null) {
            $this->db = lava_instance()->call->database();
        }
        return $this->db;
    }

    private function rows($sql, array $params = [])
    {
        return $this->connection()->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function row($sql, array $params = [])
    {
        $result = $this->connection()->raw($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function get_plans()
    {
        return $this->rows('SELECT plan_id, plan_name, duration_months, price FROM membership_plans WHERE is_active = 1 ORDER BY price ASC');
    }

    public function get_available_trainers()
    {
        return $this->rows(
            'SELECT t.trainer_id, u.full_name, t.specialization, t.experience_years, t.training_rate, t.bio
             FROM trainers t INNER JOIN users u ON u.user_id = t.user_id
             WHERE t.is_available = 1 ORDER BY u.full_name ASC'
        );
    }

    public function email_exists($email)
    {
        return (bool) $this->row('SELECT user_id FROM users WHERE email = ? LIMIT 1', [$email]);
    }

    public function check_database_connection()
    {
        $databaseConfig = database_config()['main'];
        $driver = strtolower((string) ($databaseConfig['driver'] ?? 'mysql'));
        if ($driver !== 'mysql') {
            throw new RuntimeException('The GymMate schema requires MySQL.');
        }

        $host = (string) ($databaseConfig['hostname'] ?? 'localhost');
        $port = (string) ($databaseConfig['port'] ?? '3306');
        $database = (string) ($databaseConfig['database'] ?? '');
        $charset = (string) ($databaseConfig['charset'] ?? 'utf8mb4');
        if ($database === '') {
            throw new RuntimeException('The database name is not configured.');
        }

        $sslMode = strtolower((string) ($databaseConfig['ssl_mode'] ?? 'disabled'));
        if (!in_array($sslMode, ['disabled', 'required'], true)) {
            throw new RuntimeException('DB_SSL_MODE must be either disabled or required.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($sslMode === 'required') {
            $ca = (string) ($databaseConfig['ssl_ca'] ?? '');
            if ($ca === '') {
                throw new RuntimeException('DB_SSL_MODE=required needs the database provider CA certificate set in DB_SSL_CA.');
            }
            if (!is_file($ca) || !is_readable($ca)) {
                throw new RuntimeException('The configured MySQL SSL CA certificate is missing or unreadable.');
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } elseif (!empty($databaseConfig['ssl_ca'])) {
            throw new RuntimeException('Set DB_SSL_MODE=required when configuring DB_SSL_CA.');
        }

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
            (string) ($databaseConfig['username'] ?? ''),
            (string) ($databaseConfig['password'] ?? ''),
            $options
        );
        $pdo->query('SELECT 1')->fetchColumn();

        $requiredTables = [
            'users',
            'member_health',
            'membership_plans',
            'memberships',
            'membership_payments',
            'trainers',
            'trainer_requests',
            'trainer_schedules',
            'attendance_logs',
            'trainer_sessions',
        ];
        $placeholders = implode(', ', array_fill(0, count($requiredTables), '?'));
        $statement = $pdo->prepare(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})"
        );
        $statement->execute($requiredTables);
        $installedTables = $statement->fetchAll(PDO::FETCH_COLUMN);
        $missingTables = array_values(array_diff($requiredTables, $installedTables));

        return [
            'connected' => true,
            'schema_ready' => count($missingTables) === 0,
            'missing_tables' => $missingTables,
        ];
    }

    public function register_member(array $account, array $health)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $db->table('users')->insert($account);
            $userId = (int) $db->last_id();
            if ($userId < 1) {
                throw new RuntimeException('The account could not be created.');
            }

            $health['user_id'] = $userId;
            $db->table('member_health')->insert($health);
            $db->commit();
            return $userId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function create_admin_user(array $account, array $trainer, $actorId)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $db->table('users')->insert($account);
            $userId = (int) $db->last_id();
            if ($userId < 1) {
                throw new RuntimeException('The account could not be created.');
            }

            if ($account['role'] === 'member') {
                $db->table('member_health')->insert([
                    'user_id' => $userId,
                    'height_cm' => null,
                    'weight_kg' => null,
                    'blood_pressure' => null,
                    'medical_conditions' => null,
                    'allergies' => null,
                    'previous_injuries' => null,
                    'current_medications' => null,
                    'emergency_name' => null,
                    'emergency_contact' => null,
                    'fitness_goal' => null,
                ]);
            } else {
                $db->table('trainers')->insert([
                    'user_id' => $userId,
                    'specialization' => $trainer['specialization'],
                    'experience_years' => $trainer['experience_years'],
                    'training_rate' => $trainer['training_rate'],
                    'is_available' => 1,
                ]);
            }

            $this->log_admin_action($actorId, 'user_created', 'Created ' . $account['role'] . ' account', 'users', $userId);
            $db->commit();
            return $userId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function find_user_by_email($email)
    {
        return $this->row(
            'SELECT user_id, full_name, email, password_hash, role FROM users WHERE email = ? LIMIT 1',
            [$email]
        );
    }

    public function find_user_by_id($userId)
    {
        return $this->row(
            'SELECT user_id, full_name, email, role FROM users WHERE user_id = ? LIMIT 1',
            [$userId]
        );
    }

    public function get_member_memberships($userId)
    {
        return $this->rows(
            'SELECT m.membership_id, m.start_date, m.end_date, m.status, m.created_at,
                    p.plan_name, p.duration_months, p.price,
                    mp.payment_method, mp.payment_status, mp.reference_number
             FROM memberships m
             INNER JOIN membership_plans p ON p.plan_id = m.plan_id
             LEFT JOIN membership_payments mp ON mp.membership_id = m.membership_id
             WHERE m.user_id = ? ORDER BY m.created_at DESC',
            [$userId]
        );
    }

    public function get_member_trainer_requests($userId)
    {
        return $this->rows(
            'SELECT r.request_id, r.trainer_id, r.fitness_goal, r.preferred_schedule, r.training_preference,
                    r.status, r.requested_at, u.full_name AS trainer_name, t.training_rate
             FROM trainer_requests r
             INNER JOIN trainers t ON t.trainer_id = r.trainer_id
             INNER JOIN users u ON u.user_id = t.user_id
             WHERE r.member_id = ? ORDER BY r.requested_at DESC',
            [$userId]
        );
    }

    public function get_member_sessions($userId)
    {
        return $this->rows(
            'SELECT s.session_id, s.session_date, s.start_time, s.end_time, s.status,
                    s.notes, trainer.full_name AS trainer_name
             FROM trainer_sessions s
             INNER JOIN trainers t ON t.trainer_id = s.trainer_id
             INNER JOIN users trainer ON trainer.user_id = t.user_id
             WHERE s.member_id = ? ORDER BY s.session_date DESC, s.start_time DESC',
            [$userId]
        );
    }

    public function get_member_attendance($userId)
    {
        return $this->rows(
            'SELECT a.log_id, a.check_in_time, a.scanner_location, p.plan_name
             FROM attendance_logs a
             INNER JOIN memberships m ON m.membership_id = a.membership_id
             INNER JOIN membership_plans p ON p.plan_id = m.plan_id
             WHERE a.user_id = ? ORDER BY a.check_in_time DESC LIMIT 50',
            [$userId]
        );
    }

    public function find_active_member_pass($userId)
    {
        return $this->row(
            'SELECT m.membership_id, m.start_date, m.end_date, p.plan_name
             FROM memberships m
             INNER JOIN membership_plans p ON p.plan_id = m.plan_id
             WHERE m.user_id = ? AND m.status = ? AND m.start_date <= CURDATE()
                   AND m.end_date >= CURDATE()
             ORDER BY m.end_date DESC LIMIT 1',
            [$userId, 'active']
        );
    }

    public function get_available_session_slots($userId, $requestId, $date)
    {
        $request = $this->row(
            'SELECT trainer_id FROM trainer_requests
             WHERE request_id = ? AND member_id = ? AND status = ? LIMIT 1',
            [$requestId, $userId, 'accepted']
        );
        if (!$request) {
            return null;
        }

        $day = date('l', strtotime($date));
        $schedules = $this->rows(
            'SELECT schedule_id, start_time, end_time FROM trainer_schedules
             WHERE trainer_id = ? AND day_of_week = ? ORDER BY start_time ASC',
            [(int) $request['trainer_id'], $day]
        );
        $booked = $this->rows(
            'SELECT start_time, end_time FROM trainer_sessions
             WHERE trainer_id = ? AND session_date = ? AND status = ?',
            [(int) $request['trainer_id'], $date, 'booked']
        );
        $memberBooked = $this->rows(
            'SELECT start_time, end_time FROM trainer_sessions
             WHERE member_id = ? AND session_date = ? AND status = ?',
            [$userId, $date, 'booked']
        );

        $slots = [];
        $today = date('Y-m-d');
        foreach ($schedules as $schedule) {
            $rangeStart = strtotime($date . ' ' . $schedule['start_time']);
            $rangeEnd = strtotime($date . ' ' . $schedule['end_time']);
            for ($slotStart = $rangeStart; $slotStart + 3600 <= $rangeEnd; $slotStart += 3600) {
                $slotEnd = $slotStart + 3600;
                $start = date('H:i:s', $slotStart);
                $end = date('H:i:s', $slotEnd);
                $isBooked = false;
                foreach ($booked as $session) {
                    if ($session['start_time'] < $end && $session['end_time'] > $start) {
                        $isBooked = true;
                        break;
                    }
                }
                foreach ($memberBooked as $session) {
                    if ($session['start_time'] < $end && $session['end_time'] > $start) {
                        $isBooked = true;
                        break;
                    }
                }
                if (!$isBooked && ($date !== $today || $slotStart > time())) {
                    $slots[] = [
                        'schedule_id' => (int) $schedule['schedule_id'],
                        'start_time' => substr($start, 0, 5),
                        'end_time' => substr($end, 0, 5),
                    ];
                }
            }
        }
        return $slots;
    }

    public function book_trainer_session($userId, $requestId, $scheduleId, $date, $startTime, $notes)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $request = $this->row(
                'SELECT r.trainer_id FROM trainer_requests r
                 WHERE r.request_id = ? AND r.member_id = ? AND r.status = ? FOR UPDATE',
                [$requestId, $userId, 'accepted']
            );
            if (!$request) {
                $db->rollback();
                return false;
            }
            $trainerId = (int) $request['trainer_id'];
            $day = date('l', strtotime($date));
            $schedules = $this->rows(
                'SELECT schedule_id, start_time, end_time FROM trainer_schedules
                 WHERE trainer_id = ? AND day_of_week = ? FOR UPDATE',
                [$trainerId, $day]
            );
            $selectedSchedule = null;
            foreach ($schedules as $schedule) {
                if ((int) $schedule['schedule_id'] === (int) $scheduleId) {
                    $selectedSchedule = $schedule;
                    break;
                }
            }
            if (!$selectedSchedule) {
                $db->rollback();
                return false;
            }
            $startTimeDb = $startTime . ':00';
            $rangeStart = strtotime($date . ' ' . $selectedSchedule['start_time']);
            $rangeEnd = strtotime($date . ' ' . $selectedSchedule['end_time']);
            $slotStart = strtotime($date . ' ' . $startTimeDb);
            $slotEnd = date('H:i:s', $slotStart + 3600);
            if (
                $slotStart < $rangeStart
                || $slotStart + 3600 > $rangeEnd
                || ($slotStart - $rangeStart) % 3600 !== 0
                || ($date === date('Y-m-d') && $slotStart <= time())
            ) {
                $db->rollback();
                return false;
            }

            $conflict = $this->row(
                "SELECT session_id FROM trainer_sessions
                 WHERE trainer_id = ? AND session_date = ? AND status = ?
                   AND start_time < ? AND end_time > ? LIMIT 1 FOR UPDATE",
                [$trainerId, $date, 'booked', $slotEnd, $startTimeDb]
            );
            if ($conflict) {
                $db->rollback();
                return false;
            }
            $memberConflict = $this->row(
                "SELECT session_id FROM trainer_sessions
                 WHERE member_id = ? AND session_date = ? AND status = ?
                   AND start_time < ? AND end_time > ? LIMIT 1 FOR UPDATE",
                [$userId, $date, 'booked', $slotEnd, $startTimeDb]
            );
            if ($memberConflict) {
                $db->rollback();
                return false;
            }

            $db->raw(
                'INSERT INTO trainer_sessions
                    (request_id, schedule_id, trainer_id, member_id, session_date, start_time, end_time, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$requestId, $scheduleId, $trainerId, $userId, $date, $startTimeDb, $slotEnd, $notes ?: null]
            );
            $sessionId = (int) $db->last_id();
            $this->log_activity($userId, 'member', 'trainer_session_booked', 'Trainer session booked', 'trainer_sessions', $sessionId);
            $db->commit();
            return $sessionId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function get_trainer_schedules($userId)
    {
        return $this->rows(
            'SELECT s.schedule_id, s.day_of_week, s.start_time, s.end_time
             FROM trainer_schedules s
             INNER JOIN trainers t ON t.trainer_id = s.trainer_id
             WHERE t.user_id = ?
             ORDER BY FIELD(s.day_of_week, ?, ?, ?, ?, ?, ?, ?), s.start_time',
            [$userId, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
        );
    }

    public function save_trainer_schedule($userId, $day, $startTime, $endTime)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $trainer = $this->row('SELECT trainer_id FROM trainers WHERE user_id = ? FOR UPDATE', [$userId]);
            if (!$trainer) {
                $db->rollback();
                return false;
            }
            $trainerId = (int) $trainer['trainer_id'];
            $conflict = $this->row(
                'SELECT schedule_id FROM trainer_schedules
                 WHERE trainer_id = ? AND day_of_week = ? AND start_time < ? AND end_time > ?
                 LIMIT 1 FOR UPDATE',
                [$trainerId, $day, $endTime, $startTime]
            );
            if ($conflict) {
                $db->rollback();
                return false;
            }
            $db->raw(
                'INSERT INTO trainer_schedules (trainer_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)',
                [$trainerId, $day, $startTime, $endTime]
            );
            $scheduleId = (int) $db->last_id();
            $this->log_activity($userId, 'trainer', 'trainer_schedule_added', 'Weekly availability added', 'trainer_schedules', $scheduleId);
            $db->commit();
            return $scheduleId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function get_trainer_sessions($userId)
    {
        return $this->rows(
            'SELECT s.session_id, s.session_date, s.start_time, s.end_time,
                    s.status, s.notes, member.full_name AS member_name
             FROM trainer_sessions s
             INNER JOIN users member ON member.user_id = s.member_id
             INNER JOIN trainers t ON t.trainer_id = s.trainer_id
             WHERE t.user_id = ?
             ORDER BY s.session_date DESC, s.start_time DESC',
            [$userId]
        );
    }

    public function update_trainer_session_status($userId, $sessionId, $status)
    {
        $result = $this->connection()->raw(
            "UPDATE trainer_sessions s
             INNER JOIN trainers t ON t.trainer_id = s.trainer_id
             SET s.status = ?
             WHERE s.session_id = ? AND t.user_id = ? AND s.status = 'booked'
               AND (? = 'cancelled' OR TIMESTAMP(s.session_date, s.start_time) <= NOW())",
            [$status, $sessionId, $userId, $status]
        );
        if ($result->rowCount() > 0) {
            $this->log_activity($userId, 'trainer', 'trainer_session_' . $status, 'Trainer session status updated', 'trainer_sessions', $sessionId);
            return true;
        }
        return false;
    }

    public function cancel_member_session($userId, $sessionId)
    {
        $result = $this->connection()->raw(
            "UPDATE trainer_sessions
             SET status = 'cancelled'
             WHERE session_id = ? AND member_id = ? AND status = 'booked'
               AND TIMESTAMP(session_date, start_time) > NOW()",
            [$sessionId, $userId]
        );
        if ($result->rowCount() > 0) {
            $this->log_activity($userId, 'member', 'trainer_session_cancelled', 'Member cancelled trainer session', 'trainer_sessions', $sessionId);
            return true;
        }
        return false;
    }

    public function record_attendance($userId, $membershipId, $recorderId, $recorderRole, $location)
    {
        $membership = $this->row(
            'SELECT membership_id FROM memberships
             WHERE membership_id = ? AND user_id = ? AND status = ?
               AND start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1',
            [$membershipId, $userId, 'active']
        );
        if (!$membership) {
            return false;
        }
        $this->connection()->raw(
            'INSERT INTO attendance_logs (user_id, membership_id, recorded_by, scanner_location)
             VALUES (?, ?, ?, ?)',
            [$userId, $membershipId, $recorderId, $location !== '' ? $location : null]
        );
        $logId = (int) $this->connection()->last_id();
        $this->log_activity($recorderId, $recorderRole, 'attendance_check_in', 'Member checked in', 'attendance_logs', $logId);
        return $logId;
    }

    public function get_admin_email()
    {
        $admin = $this->row("SELECT email FROM users WHERE role = 'admin' ORDER BY user_id ASC LIMIT 1");
        return $admin['email'] ?? null;
    }

    public function get_admin_recent_attendance()
    {
        return $this->rows(
            'SELECT a.log_id, a.check_in_time, a.scanner_location,
                    member.full_name AS member_name, p.plan_name
             FROM attendance_logs a
             INNER JOIN users member ON member.user_id = a.user_id
             INNER JOIN memberships m ON m.membership_id = a.membership_id
             INNER JOIN membership_plans p ON p.plan_id = m.plan_id
             ORDER BY a.check_in_time DESC LIMIT 50'
        );
    }

    public function get_member_health($userId)
    {
        return $this->row(
            'SELECT height_cm, weight_kg, blood_pressure, medical_conditions, allergies,
                    previous_injuries, current_medications, emergency_name,
                    emergency_contact, fitness_goal, updated_at
             FROM member_health WHERE user_id = ? LIMIT 1',
            [$userId]
        );
    }

    public function save_member_health($userId, array $health)
    {
        $existing = $this->row('SELECT health_id FROM member_health WHERE user_id = ? LIMIT 1', [$userId]);
        $health['updated_at'] = date('Y-m-d H:i:s');
        if ($existing) {
            $this->connection()->table('member_health')->where('user_id', $userId)->update($health);
            return;
        }

        $health['user_id'] = $userId;
        $this->connection()->table('member_health')->insert($health);
    }

    public function get_member_progress($userId)
    {
        return $this->rows(
            'SELECT pr.progress_id, pr.record_date, pr.weight_kg, pr.bmi,
                    pr.workout_completed, pr.duration_min, pr.calories_burned,
                    pr.trainer_notes, trainer.full_name AS trainer_name
             FROM progress_records pr
             INNER JOIN trainer_requests r ON r.request_id = pr.request_id
             INNER JOIN trainers t ON t.trainer_id = r.trainer_id
             INNER JOIN users trainer ON trainer.user_id = t.user_id
             WHERE r.member_id = ? ORDER BY pr.record_date DESC, pr.progress_id DESC',
            [$userId]
        );
    }

    public function get_member_training_plans($userId)
    {
        return $this->rows(
            'SELECT tp.plan_id, tp.goal, tp.created_at, ex.day_of_week,
                    ex.exercise_name, ex.sets, ex.reps, ex.duration_min, ex.notes,
                    trainer.full_name AS trainer_name
             FROM training_plans tp
             INNER JOIN trainer_requests r ON r.request_id = tp.request_id
             INNER JOIN trainers t ON t.trainer_id = r.trainer_id
             INNER JOIN users trainer ON trainer.user_id = t.user_id
             LEFT JOIN training_plan_exercises ex ON ex.plan_id = tp.plan_id
             WHERE r.member_id = ? ORDER BY tp.updated_at DESC, tp.plan_id DESC, ex.exercise_id ASC',
            [$userId]
        );
    }

    public function find_active_plan($planId)
    {
        return $this->row(
            'SELECT plan_id, plan_name, duration_months, price FROM membership_plans WHERE plan_id = ? AND is_active = 1 LIMIT 1',
            [$planId]
        );
    }

    public function create_membership_payment($userId, array $plan, $method, $reference)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $db->table('memberships')->insert([
                'user_id' => $userId,
                'plan_id' => (int) $plan['plan_id'],
                'status' => 'pending',
            ]);
            $membershipId = (int) $db->last_id();
            $db->table('membership_payments')->insert([
                'membership_id' => $membershipId,
                'amount' => $plan['price'],
                'payment_method' => $method,
                'reference_number' => $reference !== '' ? $reference : null,
                'payment_status' => 'Pending',
            ]);
            $db->commit();
            return $membershipId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function mark_membership_checkout_failed($membershipId, $userId)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $payment = $this->row(
                'SELECT mp.payment_id
                 FROM membership_payments mp
                 INNER JOIN memberships m ON m.membership_id = mp.membership_id
                 WHERE mp.membership_id = ? AND m.user_id = ? AND mp.payment_status = ? FOR UPDATE',
                [$membershipId, $userId, 'Pending']
            );
            if ($payment) {
                $db->raw(
                    'UPDATE membership_payments SET payment_status = ? WHERE payment_id = ?',
                    ['Rejected', (int) $payment['payment_id']]
                );
                $db->raw(
                    'UPDATE memberships SET status = ? WHERE membership_id = ? AND user_id = ? AND status = ?',
                    ['cancelled', $membershipId, $userId, 'pending']
                );
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function complete_paymongo_payment($reference, $amountMinor, $currency)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $payment = $this->row(
                'SELECT mp.payment_id, mp.membership_id, mp.amount, mp.payment_status,
                        m.user_id, m.status AS membership_status
                 FROM membership_payments mp
                 INNER JOIN memberships m ON m.membership_id = mp.membership_id
                 INNER JOIN membership_plans p ON p.plan_id = m.plan_id
                 WHERE mp.reference_number = ? AND mp.payment_method = ? FOR UPDATE',
                [$reference, 'GCash']
            );
            if (!$payment) {
                $db->rollback();
                return false;
            }
            if ($payment['payment_status'] === 'Paid' && $payment['membership_status'] === 'active') {
                $db->commit();
                return true;
            }
            $expectedAmount = (int) round((float) $payment['amount'] * 100);
            if (
                $payment['payment_status'] !== 'Pending'
                || $payment['membership_status'] !== 'pending'
                || $currency !== 'PHP'
                || $amountMinor !== $expectedAmount
            ) {
                $db->rollback();
                return false;
            }

            $db->raw(
                'UPDATE membership_payments SET payment_status = ?, paid_at = NOW() WHERE payment_id = ?',
                ['Paid', (int) $payment['payment_id']]
            );
            $db->raw(
                'UPDATE memberships m
                 INNER JOIN membership_plans p ON p.plan_id = m.plan_id
                 SET m.status = ?, m.start_date = CURDATE(),
                     m.end_date = DATE_ADD(CURDATE(), INTERVAL p.duration_months MONTH)
                 WHERE m.membership_id = ? AND m.status = ?',
                ['active', (int) $payment['membership_id'], 'pending']
            );
            $this->log_activity(
                (int) $payment['user_id'],
                'system',
                'membership_payment_paid',
                'PayMongo confirmed GCash membership payment',
                'membership_payments',
                (int) $payment['payment_id']
            );
            $db->commit();
            return true;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function get_member_payment_status($userId, $reference)
    {
        return $this->row(
            'SELECT mp.payment_status, mp.payment_method, p.plan_name
             FROM membership_payments mp
             INNER JOIN memberships m ON m.membership_id = mp.membership_id
             INNER JOIN membership_plans p ON p.plan_id = m.plan_id
             WHERE m.user_id = ? AND mp.reference_number = ? LIMIT 1',
            [$userId, $reference]
        );
    }

    public function trainer_is_available($trainerId)
    {
        return (bool) $this->row(
            'SELECT trainer_id FROM trainers WHERE trainer_id = ? AND is_available = 1 LIMIT 1',
            [$trainerId]
        );
    }

    public function create_trainer_request($userId, $trainerId, $goal, $schedule, $preference, $notes)
    {
        return $this->connection()->table('trainer_requests')->insert([
            'member_id' => $userId,
            'trainer_id' => $trainerId,
            'fitness_goal' => $goal,
            'preferred_schedule' => $schedule,
            'training_preference' => $preference,
            'additional_notes' => $notes !== '' ? $notes : null,
            'status' => 'pending',
        ]);
    }

    public function get_trainer_members($userId)
    {
        return $this->rows(
            "SELECT r.request_id, r.fitness_goal, r.preferred_schedule, r.status,
                    u.full_name AS member_name, u.email,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.height_cm END AS height_cm,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.weight_kg END AS weight_kg,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.blood_pressure END AS blood_pressure,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.medical_conditions END AS medical_conditions,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.allergies END AS allergies,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.previous_injuries END AS previous_injuries,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.current_medications END AS current_medications,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.emergency_name END AS emergency_name,
                    CASE WHEN r.status IN ('accepted', 'completed') THEN h.emergency_contact END AS emergency_contact
             FROM trainer_requests r
             INNER JOIN trainers t ON t.trainer_id = r.trainer_id
             INNER JOIN users u ON u.user_id = r.member_id
             LEFT JOIN member_health h ON h.user_id = u.user_id
             WHERE t.user_id = ? ORDER BY r.requested_at DESC",
            [$userId]
        );
    }

    public function get_trainer_progress($userId)
    {
        return $this->rows(
            'SELECT pr.progress_id, pr.record_date, pr.weight_kg, pr.bmi,
                    pr.workout_completed, pr.duration_min, pr.calories_burned,
                    pr.trainer_notes, member.full_name AS member_name,
                    r.request_id
             FROM progress_records pr
             INNER JOIN trainer_requests r ON r.request_id = pr.request_id
             INNER JOIN trainers t ON t.trainer_id = r.trainer_id
             INNER JOIN users member ON member.user_id = r.member_id
             WHERE t.user_id = ? ORDER BY pr.record_date DESC, pr.progress_id DESC',
            [$userId]
        );
    }

    public function create_training_plan($trainerId, $requestId, array $plan, array $exercise)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $request = $this->row(
                'SELECT r.request_id
                 FROM trainer_requests r
                 INNER JOIN trainers t ON t.trainer_id = r.trainer_id
                 WHERE r.request_id = ? AND t.user_id = ? AND r.status = ?
                 FOR UPDATE',
                [$requestId, $trainerId, 'accepted']
            );
            if (!$request) {
                $db->rollback();
                return false;
            }

            $existingPlan = $this->row(
                'SELECT plan_id FROM training_plans WHERE request_id = ? AND goal = ? ORDER BY plan_id DESC LIMIT 1 FOR UPDATE',
                [$requestId, $plan['goal']]
            );
            if ($existingPlan) {
                $planId = (int) $existingPlan['plan_id'];
                $db->raw('UPDATE training_plans SET updated_at = NOW() WHERE plan_id = ?', [$planId]);
            } else {
                $db->table('training_plans')->insert([
                    'request_id' => $requestId,
                    'goal' => $plan['goal'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $planId = (int) $db->last_id();
            }
            if ($planId < 1) {
                throw new RuntimeException('The training plan could not be created.');
            }

            $exercise['plan_id'] = $planId;
            $db->table('training_plan_exercises')->insert($exercise);
            $this->log_activity($trainerId, 'trainer', 'training_plan_created', 'Training plan created', 'training_plans', $planId);
            $db->commit();
            return $planId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function create_progress_record($trainerId, $requestId, array $record)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $request = $this->row(
                'SELECT r.request_id
                 FROM trainer_requests r
                 INNER JOIN trainers t ON t.trainer_id = r.trainer_id
                 WHERE r.request_id = ? AND t.user_id = ? AND r.status IN (?, ?)
                 FOR UPDATE',
                [$requestId, $trainerId, 'accepted', 'completed']
            );
            if (!$request) {
                $db->rollback();
                return false;
            }

            $record['request_id'] = $requestId;
            $db->table('progress_records')->insert($record);
            $progressId = (int) $db->last_id();
            if ($progressId < 1) {
                throw new RuntimeException('The progress record could not be saved.');
            }
            $this->log_activity($trainerId, 'trainer', 'progress_recorded', 'Member progress recorded', 'progress_records', $progressId);
            $db->commit();
            return $progressId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function get_admin_users()
    {
        return $this->rows(
            'SELECT u.user_id, u.full_name, u.email, u.contact_number, u.role,
                    t.specialization, t.experience_years, t.training_rate, t.is_available
             FROM users u
             LEFT JOIN trainers t ON t.user_id = u.user_id
             ORDER BY u.created_at DESC'
        );
    }

    public function get_admin_payments()
    {
        return $this->rows(
            'SELECT mp.payment_id, mp.membership_id, mp.amount, mp.payment_method,
                    mp.reference_number, mp.payment_status, mp.verified_at, mp.paid_at,
                    m.status AS membership_status, m.start_date, m.end_date,
                    p.plan_name, u.full_name, u.email
             FROM membership_payments mp
             INNER JOIN memberships m ON m.membership_id = mp.membership_id
             INNER JOIN membership_plans p ON p.plan_id = m.plan_id
             INNER JOIN users u ON u.user_id = m.user_id
             ORDER BY (mp.payment_status = ?) DESC, mp.payment_id DESC',
            ['Pending']
        );
    }

    public function get_admin_trainer_requests()
    {
        return $this->rows(
            'SELECT r.request_id, r.fitness_goal, r.preferred_schedule,
                    r.training_preference, r.additional_notes, r.status, r.requested_at,
                    member.full_name AS member_name, member.email AS member_email,
                    trainer.full_name AS trainer_name
             FROM trainer_requests r
             INNER JOIN users member ON member.user_id = r.member_id
             INNER JOIN trainers t ON t.trainer_id = r.trainer_id
             INNER JOIN users trainer ON trainer.user_id = t.user_id
             ORDER BY (r.status = ?) DESC, r.requested_at DESC',
            ['pending']
        );
    }

    public function change_user_role($targetId, $role, $actorId, array $trainer = [])
    {
        if (!in_array($role, ['member', 'trainer'], true)) {
            throw new InvalidArgumentException('Role changes are limited to member and trainer accounts.');
        }

        $db = $this->connection();
        $db->transaction();
        try {
            $target = $this->row(
                'SELECT user_id, role FROM users WHERE user_id = ? FOR UPDATE',
                [$targetId]
            );
            if (!$target) {
                $db->rollback();
                return false;
            }

            $db->raw('UPDATE users SET role = ? WHERE user_id = ?', [$role, $targetId]);
            if ($role === 'trainer') {
                $existing = $this->row('SELECT trainer_id FROM trainers WHERE user_id = ? FOR UPDATE', [$targetId]);
                if ($existing) {
                    $db->raw(
                        'UPDATE trainers SET specialization = ?, experience_years = ?, training_rate = ?, is_available = 1 WHERE user_id = ?',
                        [$trainer['specialization'], $trainer['experience_years'], $trainer['training_rate'], $targetId]
                    );
                } else {
                    $db->raw(
                        'INSERT INTO trainers (user_id, specialization, experience_years, training_rate, is_available) VALUES (?, ?, ?, ?, 1)',
                        [$targetId, $trainer['specialization'], $trainer['experience_years'], $trainer['training_rate']]
                    );
                }
            } else {
                $db->raw('UPDATE trainers SET is_available = 0 WHERE user_id = ?', [$targetId]);
            }

            $description = 'Changed user role from ' . $target['role'] . ' to ' . $role;
            $this->log_admin_action($actorId, 'role_changed', $description, 'users', $targetId);
            $db->commit();
            return true;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function review_membership_payment($paymentId, $status, $actorId)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $payment = $this->row(
                'SELECT mp.membership_id, mp.payment_status, mp.payment_method,
                        mp.reference_number, m.user_id
                 FROM membership_payments mp
                 INNER JOIN memberships m ON m.membership_id = mp.membership_id
                 WHERE mp.payment_id = ? FOR UPDATE',
                [$paymentId]
            );
            if (
                !$payment || $payment['payment_status'] !== 'Pending'
                || (
                    $payment['payment_method'] === 'GCash'
                    && strpos((string) $payment['reference_number'], 'GM-') === 0
                )
            ) {
                $db->rollback();
                return false;
            }

            if ($status === 'Verified') {
                $db->raw(
                    'UPDATE membership_payments SET payment_status = ?, verified_by = ?, verified_at = NOW(), paid_at = NOW() WHERE payment_id = ?',
                    [$status, $actorId, $paymentId]
                );
                $db->raw(
                    'UPDATE memberships m
                     INNER JOIN membership_plans p ON p.plan_id = m.plan_id
                     SET m.status = ?, m.start_date = CURDATE(),
                         m.end_date = DATE_ADD(CURDATE(), INTERVAL p.duration_months MONTH)
                     WHERE m.membership_id = ?',
                    ['active', $payment['membership_id']]
                );
            } else {
                $db->raw(
                    'UPDATE membership_payments SET payment_status = ?, verified_by = ?, verified_at = NOW() WHERE payment_id = ?',
                    [$status, $actorId, $paymentId]
                );
                $db->raw('UPDATE memberships SET status = ? WHERE membership_id = ?', ['cancelled', $payment['membership_id']]);
            }

            $this->log_admin_action(
                $actorId,
                'membership_payment_' . strtolower($status),
                'Membership payment ' . $status,
                'membership_payments',
                $paymentId
            );
            $db->commit();
            return true;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function review_trainer_request($requestId, $status, $trainerUserId)
    {
        $db = $this->connection();
        $db->transaction();
        try {
            $request = $this->row(
                'SELECT r.status
                 FROM trainer_requests r
                 INNER JOIN trainers t ON t.trainer_id = r.trainer_id
                 WHERE r.request_id = ? AND t.user_id = ? FOR UPDATE',
                [$requestId, $trainerUserId]
            );
            if (!$request || $request['status'] !== 'pending') {
                $db->rollback();
                return false;
            }

            $db->raw(
                'UPDATE trainer_requests SET status = ?, responded_at = NOW() WHERE request_id = ?',
                [$status, $requestId]
            );
            $this->log_activity(
                $trainerUserId,
                'trainer',
                'trainer_request_' . $status,
                'Trainer ' . $status . ' a coaching request',
                'trainer_requests',
                $requestId
            );
            $db->commit();
            return true;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    private function log_admin_action($actorId, $action, $description, $table, $targetId)
    {
        $this->log_activity($actorId, 'admin', $action, $description, $table, $targetId);
    }

    private function log_activity($userId, $role, $action, $description, $table, $targetId)
    {
        $this->connection()->raw(
            'INSERT INTO activity_logs (user_id, role, action, description, target_table, target_id, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $role,
                $action,
                substr($description, 0, 255),
                $table,
                $targetId,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    }

    public function get_admin_stats()
    {
        return [
            'members' => (int) $this->row("SELECT COUNT(*) AS total FROM users WHERE role = 'member'")['total'],
            'trainers' => (int) $this->row("SELECT COUNT(*) AS total FROM users WHERE role = 'trainer'")['total'],
            'pending_payments' => (int) $this->row("SELECT COUNT(*) AS total FROM membership_payments WHERE payment_status = 'Pending'")['total'],
            'pending_requests' => (int) $this->row("SELECT COUNT(*) AS total FROM trainer_requests WHERE status = 'pending'")['total'],
        ];
    }
}
