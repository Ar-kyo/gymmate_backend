<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api extends Controller
{
    public function before_action()
    {
        load_class('Security_headers', 'kernel')->apply_api();
        header('Content-Type: application/json; charset=utf-8');
    }

    public function csrf()
    {
        $this->respond([
            'tokenName' => $this->security->get_csrf_token_name(),
            'token' => $this->security->get_csrf_hash(),
        ]);
    }

    public function catalog()
    {
        $this->call->model('Gymmate_model');
        $this->respond([
            'plans' => $this->Gymmate_model->get_plans(),
            'trainers' => $this->Gymmate_model->get_available_trainers(),
        ]);
    }

    public function health()
    {
        try {
            $this->call->model('Gymmate_model');
            $this->call->library('PayMongo');
            $database = $this->Gymmate_model->check_database_connection();
            $this->respond([
                'status' => $database['schema_ready'] ? 'ok' : 'setup_required',
                'database' => $database,
                'gcash_payment_configured' => $this->PayMongo->is_configured(),
                'email_configured' => (bool) (
                    getenv('SMTP_HOST')
                    && getenv('SMTP_USER')
                    && getenv('SMTP_PASSWORD')
                    && getenv('MAIL_FROM_ADDRESS')
                ),
            ], $database['schema_ready'] ? 200 : 503);
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[GymMate health] Database check failed (%s): %s',
                get_class($exception),
                $exception->getMessage()
            ));
            $this->respond([
                'status' => 'database_unavailable',
                'database' => ['connected' => false],
                'email_configured' => (bool) (
                    getenv('SMTP_HOST')
                    && getenv('SMTP_USER')
                    && getenv('SMTP_PASSWORD')
                    && getenv('MAIL_FROM_ADDRESS')
                ),
            ], 503);
        }
    }

    public function register()
    {
        $input = $_POST;
        $required = ['full_name', 'email', 'password', 'password_confirmation'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
                $this->respond(['error' => 'Please complete all required fields.'], 422);
            }
        }

        $email = strtolower(trim($input['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(['error' => 'Enter a valid email address.'], 422);
        }
        if (strlen(trim($input['full_name'])) > 100) {
            $this->respond(['error' => 'Full name must be 100 characters or fewer.'], 422);
        }
        if (strlen($input['password']) < 8) {
            $this->respond(['error' => 'Use a password with at least 8 characters.'], 422);
        }
        if ($input['password'] !== $input['password_confirmation']) {
            $this->respond(['error' => 'The passwords do not match.'], 422);
        }
        if (!empty($input['sex']) && !in_array($input['sex'], ['Male', 'Female', 'Other'], true)) {
            $this->respond(['error' => 'Select a valid sex option.'], 422);
        }
        if (!empty($input['birthdate'])) {
            $date = DateTime::createFromFormat('Y-m-d', $input['birthdate']);
            if (!$date || $date->format('Y-m-d') !== $input['birthdate'] || $date > new DateTime('today')) {
                $this->respond(['error' => 'Enter a valid birthdate that is not in the future.'], 422);
            }
        }
        foreach (['height_cm', 'weight_kg'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '' && (!is_numeric($input[$field]) || (float) $input[$field] <= 0)) {
                $this->respond(['error' => 'Height and weight must be positive numbers.'], 422);
            }
        }
        foreach (['contact_number' => 20, 'address' => 255] as $field => $limit) {
            if (isset($input[$field]) && strlen($input[$field]) > $limit) {
                $this->respond(['error' => ucfirst(str_replace('_', ' ', $field)) . " must be {$limit} characters or fewer."], 422);
            }
        }

        $this->call->model('Gymmate_model');
        if ($this->Gymmate_model->email_exists($email)) {
            $this->respond(['error' => 'An account with this email already exists.'], 409);
        }

        try {
            $userId = $this->Gymmate_model->register_member([
                'full_name' => trim($input['full_name']),
                'email' => $email,
                'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
                'role' => 'member',
                'contact_number' => trim($input['contact_number'] ?? ''),
                'address' => trim($input['address'] ?? ''),
                'birthdate' => ($input['birthdate'] ?? '') ?: null,
                'sex' => ($input['sex'] ?? '') ?: null,
            ], [
                'height_cm' => !empty($input['height_cm']) ? $input['height_cm'] : null,
                'weight_kg' => !empty($input['weight_kg']) ? $input['weight_kg'] : null,
                'blood_pressure' => ($input['blood_pressure'] ?? '') ?: null,
                'medical_conditions' => ($input['medical_conditions'] ?? '') ?: null,
                'allergies' => ($input['allergies'] ?? '') ?: null,
                'previous_injuries' => ($input['previous_injuries'] ?? '') ?: null,
                'current_medications' => ($input['current_medications'] ?? '') ?: null,
                'emergency_name' => ($input['emergency_name'] ?? '') ?: null,
                'emergency_contact' => ($input['emergency_contact'] ?? '') ?: null,
                'fitness_goal' => ($input['fitness_goal'] ?? '') ?: null,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $this->respond(['error' => 'An account with this email already exists.'], 409);
            }
            throw $exception;
        }

        $emailSent = false;
        try {
            $this->call->library('GymmateMailer');
            $emailSent = $this->GymmateMailer->send_welcome(
                trim($input['full_name']),
                $email
            );
        } catch (Throwable $exception) {
            error_log('[GymMate mail] Welcome email delivery failed.');
        }

        $this->respond([
            'message' => 'Your GymMate account is ready.',
            'userId' => $userId,
            'emailSent' => $emailSent,
        ], 201);
    }

    public function login()
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $this->call->model('Gymmate_model');
        $user = $this->Gymmate_model->find_user_by_email($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->respond(['error' => 'The email or password is incorrect.'], 401);
        }

        $this->session->sess_regenerate(true);
        $this->session->set_userdata([
            'user_id' => (int) $user['user_id'],
            'role' => $user['role'],
            'full_name' => $user['full_name'],
        ]);
        $this->respond(['user' => $this->public_user($user)]);
    }

    public function logout()
    {
        $this->session->sess_destroy();
        $this->respond(['message' => 'Signed out.']);
    }

    public function me()
    {
        $user = $this->authenticated_user();
        $this->respond(['user' => $this->public_user($user)]);
    }

    public function dashboard()
    {
        $user = $this->authenticated_user();
        $this->call->model('Gymmate_model');
        $data = ['user' => $this->public_user($user)];

        if ($user['role'] === 'member') {
            $data['memberships'] = $this->Gymmate_model->get_member_memberships((int) $user['user_id']);
            $data['requests'] = $this->Gymmate_model->get_member_trainer_requests((int) $user['user_id']);
            $data['health'] = $this->Gymmate_model->get_member_health((int) $user['user_id']);
            $data['progress'] = $this->Gymmate_model->get_member_progress((int) $user['user_id']);
            $data['training_plans'] = $this->Gymmate_model->get_member_training_plans((int) $user['user_id']);
            $data['sessions'] = $this->Gymmate_model->get_member_sessions((int) $user['user_id']);
            $data['attendance'] = $this->Gymmate_model->get_member_attendance((int) $user['user_id']);
            $this->respond($data);
        }

        if ($user['role'] === 'trainer') {
            $data['members'] = $this->Gymmate_model->get_trainer_members((int) $user['user_id']);
            $data['progress'] = $this->Gymmate_model->get_trainer_progress((int) $user['user_id']);
            $data['schedules'] = $this->Gymmate_model->get_trainer_schedules((int) $user['user_id']);
            $data['sessions'] = $this->Gymmate_model->get_trainer_sessions((int) $user['user_id']);
            $this->respond($data);
        }

        $data['stats'] = $this->Gymmate_model->get_admin_stats();
        $data['users'] = $this->Gymmate_model->get_admin_users();
        $data['payments'] = $this->Gymmate_model->get_admin_payments();
        $data['trainer_requests'] = $this->Gymmate_model->get_admin_trainer_requests();
        $data['attendance'] = $this->Gymmate_model->get_admin_recent_attendance();
        $this->respond($data);
    }

    public function member_pass()
    {
        $member = $this->require_role('member');
        $this->call->model('Gymmate_model');
        $membership = $this->Gymmate_model->find_active_member_pass((int) $member['user_id']);
        if (!$membership) {
            $this->respond(['error' => 'An active membership is required for a gym pass.'], 404);
        }
        $secret = (string) getenv('APP_KEY');
        if (strlen($secret) < 32) {
            error_log('[GymMate pass] APP_KEY must contain at least 32 characters.');
            $this->respond(['error' => 'The secure membership pass is not configured. Please contact the administrator.'], 503);
        }
        $expiresAt = min(strtotime($membership['end_date'] . ' 23:59:59'), time() + 300);
        $payload = rtrim(strtr(base64_encode(json_encode([
            'type' => 'gymmate-pass',
            'user_id' => (int) $member['user_id'],
            'membership_id' => (int) $membership['membership_id'],
            'expires_at' => $expiresAt,
        ])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
        $this->respond([
            'token' => $payload . '.' . $signature,
            'expires_at' => date(DATE_ATOM, $expiresAt),
            'membership' => $membership,
        ]);
    }

    public function session_slots()
    {
        $member = $this->require_role('member');
        $requestId = filter_var($_GET['request_id'] ?? null, FILTER_VALIDATE_INT);
        $date = trim((string) ($_GET['date'] ?? ''));
        if (!$requestId || !$this->is_valid_session_date($date)) {
            $this->respond(['error' => 'Choose an accepted trainer request and a valid upcoming date.'], 422);
        }
        $this->call->model('Gymmate_model');
        $slots = $this->Gymmate_model->get_available_session_slots((int) $member['user_id'], (int) $requestId, $date);
        if ($slots === null) {
            $this->respond(['error' => 'Session booking is only available for an accepted trainer request.'], 403);
        }
        $this->respond(['slots' => $slots]);
    }

    public function book_session()
    {
        $member = $this->require_role('member');
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT);
        $date = trim((string) ($_POST['session_date'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $parsedTime = DateTime::createFromFormat('H:i', $startTime);
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if (
            !$requestId || !$scheduleId || !$this->is_valid_session_date($date)
            || !$parsedTime || $parsedTime->format('H:i') !== $startTime
            || strlen($notes) > 255
        ) {
            $this->respond(['error' => 'Choose a valid trainer request, date, one-hour time slot, and optional note.'], 422);
        }
        $this->call->model('Gymmate_model');
        $sessionId = $this->Gymmate_model->book_trainer_session(
            (int) $member['user_id'],
            (int) $requestId,
            (int) $scheduleId,
            $date,
            $startTime,
            $notes
        );
        if (!$sessionId) {
            $this->respond(['error' => 'That time is no longer available. Refresh available times and choose another slot.'], 409);
        }
        $this->respond(['message' => 'Your trainer session is booked.', 'session_id' => $sessionId], 201);
    }

    public function cancel_session()
    {
        $member = $this->require_role('member');
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sessionId) {
            $this->respond(['error' => 'Choose a valid session to cancel.'], 422);
        }
        $this->call->model('Gymmate_model');
        if (!$this->Gymmate_model->cancel_member_session((int) $member['user_id'], (int) $sessionId)) {
            $this->respond(['error' => 'This session cannot be cancelled. It may have started or already changed.'], 409);
        }
        $this->respond(['message' => 'Your session was cancelled.']);
    }

    public function payment_status()
    {
        $member = $this->require_role('member');
        $reference = trim((string) ($_GET['reference'] ?? ''));
        if ($reference === '' || strlen($reference) > 100) {
            $this->respond(['error' => 'A valid payment reference is required.'], 422);
        }
        $this->call->model('Gymmate_model');
        $payment = $this->Gymmate_model->get_member_payment_status((int) $member['user_id'], $reference);
        if (!$payment) {
            $this->respond(['error' => 'This payment was not found for your account.'], 404);
        }
        $this->respond(['payment' => $payment]);
    }

    public function paymongo_webhook()
    {
        $this->call->library('PayMongo');
        $signature = (string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Paymongo-Signature') === 0) {
                    $signature = (string) $value;
                    break;
                }
            }
        }

        $event = $this->PayMongo->verify_webhook((string) file_get_contents('php://input'), $signature);
        if (!$event) {
            $this->respond(['error' => 'Invalid payment webhook signature.'], 400);
        }
        if (($event['data']['type'] ?? '') !== 'checkout_session.payment.paid') {
            $this->respond(['received' => true]);
        }

        $session = $event['data']['data'] ?? null;
        $attributes = is_array($session) ? ($session['attributes'] ?? null) : null;
        $reference = is_array($attributes) ? (string) ($attributes['reference_number'] ?? '') : '';
        $paidPayment = null;
        if (is_array($attributes['payments'] ?? null)) {
            foreach ($attributes['payments'] as $payment) {
                $paymentAttributes = $payment['attributes'] ?? [];
                if (
                    ($paymentAttributes['status'] ?? '') === 'paid'
                    && ($paymentAttributes['source']['type'] ?? '') === 'gcash'
                ) {
                    $paidPayment = $paymentAttributes;
                    break;
                }
            }
        }
        $paidAmount = is_array($paidPayment)
            ? filter_var($paidPayment['amount'] ?? null, FILTER_VALIDATE_INT)
            : false;
        if ($reference === '' || $paidAmount === false) {
            error_log('[GymMate PayMongo] Paid webhook is missing a GCash payment or order reference.');
            $this->respond(['error' => 'The paid GCash transaction could not be matched.'], 422);
        }

        $this->call->model('Gymmate_model');
        if (!$this->Gymmate_model->complete_paymongo_payment(
            $reference,
            $paidAmount,
            (string) ($paidPayment['currency'] ?? '')
        )) {
            error_log('[GymMate PayMongo] Paid webhook did not match a pending membership order.');
            $this->respond(['error' => 'The paid transaction did not match a pending membership.'], 422);
        }
        $this->respond(['received' => true]);
    }

    public function trainer_save_schedule()
    {
        $trainer = $this->require_role('trainer');
        $day = (string) ($_POST['day_of_week'] ?? '');
        $start = trim((string) ($_POST['start_time'] ?? ''));
        $end = trim((string) ($_POST['end_time'] ?? ''));
        $allowedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $startTime = DateTime::createFromFormat('H:i', $start);
        $endTime = DateTime::createFromFormat('H:i', $end);
        if (
            !in_array($day, $allowedDays, true) || !$startTime || !$endTime
            || $startTime->format('H:i') !== $start || $endTime->format('H:i') !== $end
            || $start >= $end
        ) {
            $this->respond(['error' => 'Choose a valid weekday and an end time after the start time.'], 422);
        }
        $this->call->model('Gymmate_model');
        if (!$this->Gymmate_model->save_trainer_schedule((int) $trainer['user_id'], $day, $start, $end)) {
            $this->respond(['error' => 'This availability overlaps an existing schedule or your trainer profile is missing.'], 409);
        }
        $this->respond(['message' => 'Weekly availability added.'], 201);
    }

    public function trainer_update_session()
    {
        $trainer = $this->require_role('trainer');
        $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$sessionId || !in_array($status, ['completed', 'cancelled'], true)) {
            $this->respond(['error' => 'Choose a valid session and action.'], 422);
        }
        $this->call->model('Gymmate_model');
        if (!$this->Gymmate_model->update_trainer_session_status((int) $trainer['user_id'], (int) $sessionId, $status)) {
            $this->respond(['error' => 'This session cannot be updated. It may have started or already changed.'], 409);
        }
        $this->respond(['message' => 'Session marked ' . $status . '.']);
    }

    public function attendance_check_in()
    {
        $scanner = $this->authenticated_user();
        if (!in_array($scanner['role'], ['admin', 'trainer'], true)) {
            $this->respond(['error' => 'Only a trainer or administrator can scan membership passes.'], 403);
        }
        $token = trim((string) ($_POST['token'] ?? ''));
        if (strlen($token) > 2048 || substr_count($token, '.') !== 1) {
            $this->respond(['error' => 'The membership QR code is invalid or expired.'], 422);
        }
        [$payload, $providedSignature] = explode('.', $token, 2);
        $secret = (string) getenv('APP_KEY');
        $expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
        if ($secret === '' || !hash_equals($expectedSignature, $providedSignature)) {
            $this->respond(['error' => 'The membership QR code is invalid or expired.'], 422);
        }
        $encodedPayload = strtr($payload, '-_', '+/');
        $encodedPayload .= str_repeat('=', (4 - strlen($encodedPayload) % 4) % 4);
        $decoded = base64_decode($encodedPayload, true);
        $pass = $decoded === false ? null : json_decode($decoded, true);
        if (
            !is_array($pass) || ($pass['type'] ?? '') !== 'gymmate-pass'
            || !isset($pass['user_id'], $pass['membership_id'], $pass['expires_at'])
            || !is_int($pass['user_id']) || !is_int($pass['membership_id'])
            || !is_int($pass['expires_at']) || $pass['expires_at'] < time()
            || $pass['expires_at'] > time() + 360
        ) {
            $this->respond(['error' => 'The membership QR code is invalid or expired. Ask the member to refresh their pass.'], 422);
        }
        $this->call->model('Gymmate_model');
        $logId = $this->Gymmate_model->record_attendance(
            (int) $pass['user_id'],
            (int) $pass['membership_id'],
            (int) $scanner['user_id'],
            $scanner['role'],
            'GymMate'
        );
        if (!$logId) {
            $this->respond(['error' => 'This pass does not have a currently active membership.'], 409);
        }
        $this->respond(['message' => 'Member checked in successfully.', 'attendance_id' => $logId]);
    }

    public function contact()
    {
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            $this->respond(['message' => 'Your message has been sent.']);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $message = trim((string) ($_POST['message'] ?? ''));
        if (
            $name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 254 || $message === '' || strlen($message) > 5000
        ) {
            $this->respond(['error' => 'Enter your name, a valid email address, and a message up to 5000 characters.'], 422);
        }
        $lastSent = (int) $this->session->userdata('contact_last_sent');
        if ($lastSent && time() - $lastSent < 60) {
            $this->respond(['error' => 'Please wait a minute before sending another message.'], 429);
        }
        $this->call->model('Gymmate_model');
        $adminEmail = $this->Gymmate_model->get_admin_email();
        if (!$adminEmail) {
            $this->respond(['error' => 'GymMate could not find an administrator contact. Please try again later.'], 503);
        }
        try {
            $this->call->library('GymmateMailer');
            if (!$this->GymmateMailer->send_contact($adminEmail, $name, $email, $message)) {
                error_log('[GymMate mail] Contact message delivery failed.');
                $this->respond(['error' => 'Your message could not be sent right now. Please try again later.'], 503);
            }
        } catch (Throwable $exception) {
            error_log('[GymMate mail] Contact message delivery failed.');
            $this->respond(['error' => 'Your message could not be sent right now. Please try again later.'], 503);
        }
        $this->session->set_userdata('contact_last_sent', time());
        $this->respond(['message' => 'Your message has been sent to the GymMate administrator.']);
    }

    public function admin_change_role()
    {
        $admin = $this->require_role('admin');
        $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = (string) ($_POST['role'] ?? '');
        if (!$targetId || !in_array($role, ['member', 'trainer'], true)) {
            $this->respond(['error' => 'Select a valid account and assign it as a member or trainer. Admin access is managed separately.'], 422);
        }
        if ((int) $targetId === (int) $admin['user_id']) {
            $this->respond(['error' => 'You cannot change your own role.'], 422);
        }

        $trainer = [];
        if ($role === 'trainer') {
            $specialization = trim((string) ($_POST['specialization'] ?? ''));
            $experience = trim((string) ($_POST['experience_years'] ?? ''));
            $rate = $_POST['training_rate'] ?? '';
            if (strlen($specialization) > 100 || ($experience !== '' && (!ctype_digit($experience) || (int) $experience > 80))) {
                $this->respond(['error' => 'Enter a specialization up to 100 characters and valid experience from 0 to 80 years.'], 422);
            }
            if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate <= 0 || (float) $rate > 99999999.99) {
                $this->respond(['error' => 'Enter a valid positive trainer session rate.'], 422);
            }
            $trainer = [
                'specialization' => $specialization !== '' ? $specialization : null,
                'experience_years' => $experience !== '' ? (int) $experience : null,
                'training_rate' => number_format((float) $rate, 2, '.', ''),
            ];
        }

        $this->call->model('Gymmate_model');
        if (!$this->Gymmate_model->change_user_role(
            (int) $targetId,
            $role,
            (int) $admin['user_id'],
            $trainer
        )) {
            $this->respond(['error' => 'That account could not be found. Refresh the dashboard and try again.'], 404);
        }
        $this->respond(['message' => 'Account role updated.']);
    }

    public function admin_create_user()
    {
        $admin = $this->require_role('admin');
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
        $role = (string) ($_POST['role'] ?? '');
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));

        if ($fullName === '' || strlen($fullName) > 100 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(['error' => 'Enter a name up to 100 characters and a valid email address.'], 422);
        }
        if (strlen($password) < 8 || $password !== $passwordConfirmation) {
            $this->respond(['error' => 'Use a password with at least 8 characters and make sure both password fields match.'], 422);
        }
        if (!in_array($role, ['member', 'trainer'], true)) {
            $this->respond(['error' => 'Choose whether this account is a member or trainer.'], 422);
        }
        if (strlen($contactNumber) > 20) {
            $this->respond(['error' => 'Contact number must be 20 characters or fewer.'], 422);
        }

        $trainer = [];
        if ($role === 'trainer') {
            $specialization = trim((string) ($_POST['specialization'] ?? ''));
            $experience = trim((string) ($_POST['experience_years'] ?? ''));
            $rate = $_POST['training_rate'] ?? '';
            if ($specialization === '' || strlen($specialization) > 100
                || ($experience !== '' && (!ctype_digit($experience) || (int) $experience > 80))) {
                $this->respond(['error' => 'Enter a trainer specialization and valid experience from 0 to 80 years.'], 422);
            }
            if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate <= 0 || (float) $rate > 99999999.99) {
                $this->respond(['error' => 'Enter a valid positive trainer session rate.'], 422);
            }
            $trainer = [
                'specialization' => $specialization,
                'experience_years' => $experience !== '' ? (int) $experience : null,
                'training_rate' => number_format((float) $rate, 2, '.', ''),
            ];
        }

        $this->call->model('Gymmate_model');
        try {
            $this->Gymmate_model->create_admin_user([
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'contact_number' => $contactNumber,
                'address' => '',
                'birthdate' => null,
                'sex' => null,
            ], $trainer, (int) $admin['user_id']);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $this->respond(['error' => 'An account with this email already exists.'], 409);
            }
            throw $exception;
        }

        $this->respond(['message' => ucfirst($role) . ' account created. Share the temporary password securely.'], 201);
    }

    public function admin_review_payment()
    {
        $admin = $this->require_role('admin');
        $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$paymentId || !in_array($status, ['Verified', 'Rejected'], true)) {
            $this->respond(['error' => 'Choose a valid payment and review action.'], 422);
        }

        $this->call->model('Gymmate_model');
        if (!$this->Gymmate_model->review_membership_payment(
            (int) $paymentId,
            $status,
            (int) $admin['user_id']
        )) {
            $this->respond(['error' => 'This payment is no longer pending. Refresh the dashboard before reviewing it.'], 409);
        }
        $this->respond(['message' => 'Membership payment ' . strtolower($status) . '.']);
    }

    public function trainer_review_request()
    {
        $trainer = $this->require_role('trainer');
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$requestId || !in_array($status, ['accepted', 'declined'], true)) {
            $this->respond(['error' => 'Choose a valid trainer request and review action.'], 422);
        }

        $this->call->model('Gymmate_model');
        if (!$this->Gymmate_model->review_trainer_request(
            (int) $requestId,
            $status,
            (int) $trainer['user_id']
        )) {
            $this->respond(['error' => 'This request is not pending or is not assigned to your trainer account. Refresh your dashboard and try again.'], 409);
        }
        $this->respond(['message' => 'Trainer request ' . $status . '.']);
    }

    public function save_member_health()
    {
        $user = $this->require_role('member');
        $input = $_POST;
        $numbers = [
            'height_cm' => [1, 300],
            'weight_kg' => [1, 700],
        ];
        $health = [];
        foreach ($numbers as $field => [$min, $max]) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '' && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < $min || (float) $value > $max)) {
                $this->respond(['error' => 'Enter a valid ' . str_replace('_', ' ', $field) . '.'], 422);
            }
            $health[$field] = $value === '' ? null : $value;
        }

        $limits = [
            'blood_pressure' => 10,
            'emergency_name' => 100,
            'emergency_contact' => 20,
            'fitness_goal' => 100,
            'medical_conditions' => 5000,
            'allergies' => 5000,
            'previous_injuries' => 5000,
            'current_medications' => 5000,
        ];
        foreach ($limits as $field => $limit) {
            $value = trim((string) ($input[$field] ?? ''));
            if (strlen($value) > $limit) {
                $this->respond(['error' => ucfirst(str_replace('_', ' ', $field)) . " must be {$limit} characters or fewer."], 422);
            }
            $health[$field] = $value === '' ? null : $value;
        }

        $this->call->model('Gymmate_model');
        $this->Gymmate_model->save_member_health((int) $user['user_id'], $health);
        $this->respond(['message' => 'Your health and fitness profile has been saved.']);
    }

    public function trainer_create_plan()
    {
        $trainer = $this->require_role('trainer');
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        $goal = trim((string) ($_POST['goal'] ?? ''));
        $day = (string) ($_POST['day_of_week'] ?? '');
        $exerciseName = trim((string) ($_POST['exercise_name'] ?? ''));
        $allowedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        if (!$requestId || $goal === '' || strlen($goal) > 100 || !in_array($day, $allowedDays, true) || $exerciseName === '' || strlen($exerciseName) > 100) {
            $this->respond(['error' => 'Complete the training plan with a valid goal, day, and exercise.'], 422);
        }

        $exercise = [
            'day_of_week' => $day,
            'exercise_name' => $exerciseName,
            'sets' => $this->optional_positive_int($_POST['sets'] ?? '', 100),
            'reps' => $this->optional_positive_int($_POST['reps'] ?? '', 1000),
            'duration_min' => $this->optional_positive_int($_POST['duration_min'] ?? '', 1440),
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        if ($exercise['notes'] !== null && strlen($exercise['notes']) > 255) {
            $this->respond(['error' => 'Exercise notes must be 255 characters or fewer.'], 422);
        }

        $this->call->model('Gymmate_model');
        $planId = $this->Gymmate_model->create_training_plan(
            (int) $trainer['user_id'],
            (int) $requestId,
            ['goal' => $goal],
            $exercise
        );
        if (!$planId) {
            $this->respond(['error' => 'Plans can only be added for an accepted request assigned to your trainer account.'], 403);
        }
        $this->respond(['message' => 'Training plan and exercise saved.', 'plan_id' => $planId], 201);
    }

    public function trainer_record_progress()
    {
        $trainer = $this->require_role('trainer');
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        $date = trim((string) ($_POST['record_date'] ?? ''));
        $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
        if (!$requestId || !$parsedDate || $parsedDate->format('Y-m-d') !== $date || $parsedDate > new DateTime('today')) {
            $this->respond(['error' => 'Choose an assigned member and a valid date that is not in the future.'], 422);
        }

        $numericFields = [
            'weight_kg' => [0.1, 700],
            'bmi' => [1, 100],
        ];
        $completed = (string) ($_POST['workout_completed'] ?? '0');
        if (!in_array($completed, ['0', '1'], true)) {
            $this->respond(['error' => 'Choose whether the workout was completed.'], 422);
        }
        $record = ['record_date' => $date, 'workout_completed' => (int) $completed];
        foreach ($numericFields as $field => [$min, $max]) {
            $value = trim((string) ($_POST[$field] ?? ''));
            if ($value !== '' && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < $min || (float) $value > $max)) {
                $this->respond(['error' => 'Enter a valid ' . str_replace('_', ' ', $field) . '.'], 422);
            }
            $record[$field] = $value === '' ? null : $value;
        }
        foreach (['duration_min' => 1440, 'calories_burned' => 50000] as $field => $max) {
            $record[$field] = $this->optional_positive_int($_POST[$field] ?? '', $max);
        }
        $notes = trim((string) ($_POST['trainer_notes'] ?? ''));
        if (strlen($notes) > 5000) {
            $this->respond(['error' => 'Trainer notes must be 5000 characters or fewer.'], 422);
        }
        $record['trainer_notes'] = $notes === '' ? null : $notes;

        $this->call->model('Gymmate_model');
        $progressId = $this->Gymmate_model->create_progress_record(
            (int) $trainer['user_id'],
            (int) $requestId,
            $record
        );
        if (!$progressId) {
            $this->respond(['error' => 'Progress can only be recorded for an accepted or completed request assigned to your trainer account.'], 403);
        }
        $this->respond(['message' => 'Member progress recorded.', 'progress_id' => $progressId], 201);
    }

    public function subscribe()
    {
        $user = $this->require_role('member');
        $planId = filter_var($_POST['plan_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$planId) {
            $this->respond(['error' => 'Choose a valid membership plan.'], 422);
        }

        $this->call->library('PayMongo');
        if (!$this->PayMongo->is_configured()) {
            $this->respond([
                'error' => 'GCash checkout is not configured. Add your PayMongo test keys and webhook signing secret to the private .env file.',
            ], 503);
        }
        $this->call->model('Gymmate_model');
        $plan = $this->Gymmate_model->find_active_plan($planId);
        if (!$plan) {
            $this->respond(['error' => 'That membership plan is no longer available.'], 404);
        }
        $reference = 'GM-' . bin2hex(random_bytes(16));
        $membershipId = $this->Gymmate_model->create_membership_payment(
            (int) $user['user_id'],
            $plan,
            'GCash',
            $reference
        );
        try {
            $checkout = $this->PayMongo->create_checkout_session($plan, $membershipId, $reference);
        } catch (Throwable $exception) {
            $this->Gymmate_model->mark_membership_checkout_failed($membershipId, (int) $user['user_id']);
            error_log('[GymMate PayMongo] Could not start membership checkout.');
            $this->respond(['error' => $exception->getMessage()], 503);
        }
        $this->respond([
            'message' => 'Continue to PayMongo to pay securely with GCash.',
            'checkout_url' => $checkout['checkout_url'],
        ], 201);
    }

    public function request_trainer()
    {
        $user = $this->require_role('member');
        $trainerId = filter_var($_POST['trainer_id'] ?? null, FILTER_VALIDATE_INT);
        $goal = trim((string) ($_POST['fitness_goal'] ?? ''));
        $schedule = trim((string) ($_POST['preferred_schedule'] ?? ''));
        $preference = trim((string) ($_POST['training_preference'] ?? ''));
        if (!$trainerId || $goal === '' || $schedule === '' || $preference === '') {
            $this->respond(['error' => 'Complete the trainer request details before submitting.'], 422);
        }
        if (strlen($goal) > 100 || strlen($schedule) > 100 || strlen($preference) > 100) {
            $this->respond(['error' => 'Fitness goal, schedule, and training preference must be 100 characters or fewer.'], 422);
        }

        $this->call->model('Gymmate_model');
        if (!$this->Gymmate_model->trainer_is_available($trainerId)) {
            $this->respond(['error' => 'That trainer is not currently available.'], 404);
        }

        $this->Gymmate_model->create_trainer_request(
            (int) $user['user_id'],
            $trainerId,
            $goal,
            $schedule,
            $preference,
            trim((string) ($_POST['additional_notes'] ?? ''))
        );
        $this->respond(['message' => 'Your trainer request has been sent.'], 201);
    }

    private function authenticated_user()
    {
        $userId = (int) $this->session->userdata('user_id');
        if (!$userId) {
            $this->respond(['error' => 'Please sign in to continue.'], 401);
        }

        $this->call->model('Gymmate_model');
        $user = $this->Gymmate_model->find_user_by_id($userId);
        if (!$user) {
            $this->session->sess_destroy();
            $this->respond(['error' => 'Your account could not be found. Please sign in again.'], 401);
        }
        return $user;
    }

    private function require_role($role)
    {
        $user = $this->authenticated_user();
        if ($user['role'] !== $role) {
            $this->respond(['error' => 'You do not have permission to perform this action.'], 403);
        }
        return $user;
    }

    private function public_user(array $user)
    {
        return [
            'user_id' => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    private function optional_positive_int($value, $max)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $max) {
            $this->respond(['error' => "Enter a whole number from 1 to {$max}."], 422);
        }
        return (int) $value;
    }

    private function is_valid_session_date($date)
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return false;
        }
        $today = new DateTime('today');
        $latest = (clone $today)->modify('+90 days');
        return $parsed >= $today && $parsed <= $latest;
    }

    private function respond(array $payload, $status = 200)
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
