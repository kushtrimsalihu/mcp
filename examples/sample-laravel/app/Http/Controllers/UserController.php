<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Minimal, Laravel-shaped controller used by the MCP-AI demo scenario.
 * This file is a *fixture* — it is not loaded by any runtime, and the
 * SQL-injection sample inside `show()` exists only so the three MCP tools
 * have something realistic to find.
 */
class UserController extends Controller
{
    public function index()
    {
        return User::query()->paginate(25);
    }

    public function show($id)
    {
        // Safe implementation (initial state).
        return User::findOrFail($id);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
        ]);
        return User::create($data);
    }
}
