<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Welcome extends Controller
{
    public function index()
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'application' => 'GymMate API',
            'message' => 'The React frontend is in the frontend folder.',
            'catalog' => site_url('api/catalog'),
        ], JSON_UNESCAPED_SLASHES);
    }
}
