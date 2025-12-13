<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('roles', 'permissions')
            ->where('email', 'like', '%@5core.com')
            ->get();

        $roles = Role::all();            
        $permissions = Permission::all(); 

        return view('admin.users.index', compact('users', 'roles', 'permissions'));
    }
   public function store(Request $request)
  {
    try {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:users,email',
            'password'    => 'required|string|min:6',
            'roles'       => 'required',
            'permissions' => 'nullable|array',
        ]);
        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'role'     =>'admin',
            'password' => bcrypt($validated['password']),
        ]);
        if (!empty($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }
        if (!empty($validated['permissions'])) {
            $user->syncPermissions($validated['permissions']);
        }
        return redirect()
            ->route('users.index')
            ->with('success', 'User created successfully.');

    } catch (\Exception $e) {
        return redirect()
            ->back()
            ->withErrors(['error' => 'Something went wrong: ' . $e->getMessage()])
            ->withInput();
    }
}
public function update(Request $request, $id)
{
    $validated = $request->validate([
        'name'        => 'required|string|max:255',
        'email'       => 'required|email|unique:users,email,' . $id,
        'roles'       => 'nullable|array',
        'roles.*'     => 'string|exists:roles,name',
        'permissions' => 'nullable|array',
        'permissions.*' => 'string|exists:permissions,name',
    ]);

    try {
        $user = User::findOrFail($id);
        $user->update([
            'name'  => $validated['name'],
            'email' => $validated['email'],
        ]);

        if ($request->filled('roles')) {
            $user->syncRoles($request->roles);
        } else {
            $user->syncRoles([]); 
        }
        if ($request->filled('permissions')) {
            $user->syncPermissions($request->permissions);
        } else {
            $user->syncPermissions([]); 
        }

        return redirect()->back()->with('success', 'User updated successfully!');
    } catch (\Exception $e) {
        return redirect()->back()->with('error', 'Something went wrong: ' . $e->getMessage());
    }
}



}
