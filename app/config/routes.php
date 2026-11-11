<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');
$router->get('/api/csrf', 'Api::csrf');
$router->get('/api/health', 'Api::health');
$router->get('/api/catalog', 'Api::catalog');
$router->get('/api/me', 'Api::me');
$router->get('/api/dashboard', 'Api::dashboard');
$router->post('/api/register', 'Api::register');
$router->post('/api/login', 'Api::login');
$router->post('/api/logout', 'Api::logout');
$router->post('/api/memberships', 'Api::subscribe');
$router->get('/api/memberships/payment-status', 'Api::payment_status');
$router->post('/api/payments/paymongo-webhook', 'Api::paymongo_webhook');
$router->post('/api/trainer-requests', 'Api::request_trainer');
$router->post('/api/admin/users', 'Api::admin_create_user');
$router->post('/api/admin/users/role', 'Api::admin_change_role');
$router->post('/api/admin/membership-payments', 'Api::admin_review_payment');
$router->post('/api/trainer/requests', 'Api::trainer_review_request');
$router->post('/api/member/health', 'Api::save_member_health');
$router->post('/api/trainer/training-plans', 'Api::trainer_create_plan');
$router->post('/api/trainer/progress', 'Api::trainer_record_progress');
$router->get('/api/member/pass', 'Api::member_pass');
$router->get('/api/member/session-slots', 'Api::session_slots');
$router->post('/api/member/sessions', 'Api::book_session');
$router->post('/api/member/sessions/cancel', 'Api::cancel_session');
$router->post('/api/trainer/schedules', 'Api::trainer_save_schedule');
$router->post('/api/trainer/sessions', 'Api::trainer_update_session');
$router->post('/api/attendance/check-in', 'Api::attendance_check_in');
$router->post('/api/contact', 'Api::contact');