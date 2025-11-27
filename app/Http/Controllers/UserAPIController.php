<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth; 
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Crypt;
use Laravel\Passport\Token;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\LoginAudits;
use App\Models\User;
use App\Models\UserDetails;
use App\Models\Roles;
use App\Models\Sectors;

use App\Http\Controllers\CommonFunctionsController;
use App\Models\DocumentAuditTrial;

use Mail;

class UserAPIController extends Controller
{

    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'email|required',
                'password' => 'required',
                'type' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }
            if($request->get('type') == "normal"){
                $user_data = [
                    'email' => $request->get('email'),
                    'password' => $request->get('password'),
                    'user_type' => 'normal'
                ];
                if (Auth::attempt($user_data)) {
                    LoginAudits::create([
                        'email' => $request->get('email'),
                        'date_time' => now(),
                        'ip_address' => $request->ip(),
                        'latitude' => $request->get('latitude'),
                        'longitude' => $request->get('longitude'),
                        'status' => "success",
                    ]);
    
                    $user = Auth::user();
                    $user_details = User::where('id', $user->id)->with('userDetails')->first();
                    $response = [
                        'token' => $user->createToken('Web Token')->accessToken,
                        'email' => $user->email,
                        'id' => $user->id,
                        'name' => $user_details->userDetails->first_name. ' '  .$user_details->userDetails->last_name,
                        'type' => "normal"
                    ];
    
                    return response()->json([
                        'status' => "success",
                        'message' => 'User login successful',
                        'data' => $response,
                    ], 201);
                } else {
                    LoginAudits::create([
                        'email' => $request->get('email'),
                        'date_time' => now(),
                        'ip_address' => $request->ip(),
                        'latitude' => $request->get('latitude'),
                        'longitude' => $request->get('longitude'),
                        'status' => "fail",
                    ]);
    
                    return response()->json([
                        'status' => "fail",
                        'message' => 'User login failed',
                        'data' => null,
                    ], 500);
                }
            }
           else{
                $user_data = [
                    'email' => $request->get('email'),
                    'password' => $request->get('password'),
                    'user_type' => 'super_admin'
                ];
                if (Auth::attempt($user_data)) {
                    $user = Auth::user();
                    $user_details = User::where('id', $user->id)->with('userDetails')->first();
                    $response = [
                        'token' => $user->createToken('Web Token')->accessToken,
                        'email' => $user->email,
                        'id' => $user->id,
                        'name' => $user_details->userDetails->first_name. ' '  .$user_details->userDetails->last_name,
                        'type' => "super_admin"
                    ];
    
                    return response()->json([
                        'status' => "success",
                        'message' => 'User login successful',
                        'data' => $response,
                    ], 201);
                }
                else{
                    return response()->json([
                        'status' => "fail",
                        'message' => 'User login failed',
                        'data' => null,
                    ], 500);
                }
           }
            
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function login_with_ad(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'email|required',
                'password' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }
            $email = $request->input('email');
            $password = $request->input('password');

            $loginUser= new CommonFunctionsController();
            $tokenResponse  = $loginUser->authenticateWithAzureAD($email, $password);

            if (!$tokenResponse['authenticated']) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            $userInfo = $loginUser->getAzureADUserInfo($tokenResponse['access_token']);

            if (!$userInfo['success']) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Failed to fetch user details from Azure AD.',
                'error' => $userInfo['error'],
            ], 500);
            }

            if (!$userInfo['data']['accountEnabled']) {
                return response()->json([
                    'status' => 'fail',
                    'message' => 'User is disabled in Azure AD. Please contact support.',
                ], 403);
            }

            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'User login failed',
                    'data' => null,
                ], 500);
            }

            $user_data = [
                'email' => $request->get('email'),
                'user_type' => 'ad'
            ];
            if (Auth::attempt($user_data)) {
                $user = Auth::user();
                $user_details = User::where('id', $user->id)->with('userDetails')->first();
                $response = [
                    'token' => $user->createToken('Web Token')->accessToken,
                    'email' => $user->email,
                    'id' => $user->id,
                    'name' => $user_details->userDetails->first_name. ' '  .$user_details->userDetails->last_name,
                    'type' => "ad"
                ];

                return response()->json([
                    'status' => "success",
                    'message' => 'User login successful',
                    'data' => $response,
                ], 201);
            }
            else{
                return response()->json([
                    'status' => "fail",
                    'message' => 'User login failed',
                    'data' => null,
                ], 500);
            }
            
        } catch (\Exception $e) {
  
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function add_user(Request $request)
    {
        try {
          
            $validator = Validator::make($request->all(), [
                'first_name' => 'required',
                'last_name' => 'required',
                'mobile_no' => 'required',
                'email' => 'required|email|unique:users,email',
                'sector' => 'required',
                'password' => [
                'required',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                ],
                'password_confirmation' => 'required|same:password'
            ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            $user = User::create([
                "email" => $request->email,
                "password" => Hash::make($request->password),
                "role" => $request->role,
                "supervisors" => $request->supervisors,
                "user_type" => 'normal'
            ]);

            $userDetails = new UserDetails();
            $userDetails->user_id = $user->id;
            $userDetails->first_name = $request->first_name;
            $userDetails->last_name = $request->last_name;
            $userDetails->mobile_no = $request->mobile_no;
            $userDetails->sector = $request->sector;
            $userDetails->save();

            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('new user added','user', $userId, $user->id, $date_time, null, null);
    
            return response()->json([
                'status' => "success",
                'message' => 'User added'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function user_details($id, Request $request)
{
    try {
        if ($request->isMethod('get')) {
            $user = User::where('id', $id)->with('userDetails')->first();
            if ($user->userDetails->sector == null || $user->userDetails->sector == '') {
                $user->userDetails->sector_name = 'none';
            } else {
                $user->userDetails->sector_name = Sectors::where('id', $user->userDetails->sector)->value('sector_name') ?? 'none';
            }
            return response()->json($user);
        }

        if ($request->isMethod('post')) {

            $validator = Validator::make($request->all(), [
                'first_name' => 'required',
                'last_name' => 'required',
                'mobile_no' => 'required',
                'email' => 'required|email',
                'sector' => 'required',
                'role'   => 'required' // ✅ enforce role
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ✅ Email validation
            if (User::where("id", $id)->where("email", $request->email)->exists()) {
                $email = $request->email;
            } elseif (User::where("email", $request->email)->exists()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'This email is already in use',
                ], 500);
            } else {
                $email = $request->email;
            }

            // ✅ role should replace existing
            $roleData = is_string($request->role)
                ? json_decode($request->role, true)
                : $request->role;

            if (!is_array($roleData)) {
                $roleData = [$roleData]; // force it into array
            }

            // ✅ replace role instead of merging
            $newRolesArray = $roleData;

            // ✅ supervisors can stay merged
            $existingSupervisors = User::where('id', $id)->first();
            $existingSupervisorsArray = json_decode($existingSupervisors->supervisors, true) ?? [];
            $supervisorData = is_string($request->supervisors)
                ? json_decode($request->supervisors, true)
                : $request->supervisors;

            $newSupervisorsArray = array_unique(array_merge($existingSupervisorsArray, $supervisorData));

            // ✅ Update user details
            $userDetails = UserDetails::where('user_id', $id)->first();
            $userDetails->first_name = $request->first_name;
            $userDetails->last_name = $request->last_name;
            $userDetails->mobile_no = $request->mobile_no;
            $userDetails->sector = $request->sector;
            $userDetails->update();

            // ✅ Update user
            $user = User::find($id);
            $user->email = $email;
            $user->role = $newRolesArray; // overwrite
            $user->supervisors = $newSupervisorsArray;
            $user->update();

            // ✅ Audit
            $userId = auth('api')->id();
            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('user details updated', 'user', $userId, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'User updated'
            ], 201);
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

    public function update_password(Request $request)
    {
         
        try {

            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'current_password' => 'required',
                'password' => [
                'required',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                ],
                'password_confirmation' => 'required|same:password'
            ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            if (Hash::check($request->input('current_password'), User::where('email', $request->email)->value('password'))) {

                $user = User::where('email', $request->email)->first();
                $user->password = Hash::make($request->input('password'));
                $user->update();

                $userId = auth('api')->id();

                $date_time = Carbon::now()->format('Y-m-d H:i:s');
                $auditFunction = new CommonFunctionsController();
                $auditFunction->document_audit_trail('user password updated','user', $userId, 'other', $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Password updated'
                ], 201);

               }
               else{
                return response()->json([
                    'status' => "fail",
                    'message' => 'Current password is incorrect.',
                ], 500);
            }

        } catch (\Exception $e) {

           

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);

        }    
    }

    public function delete_user($id,Request $request)
    {
         
        try {
            UserDetails::where('user_id', '=', $id)->delete();
            User::where('id', $id)->delete(); 

            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('user deleted','user', $userId, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'User Deleted'
            ], 201);
        
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }


    public function users(Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $users = User::select('id', 'email', 'role')->where('user_type', '!=', 'super_admin') 
                ->with(['userDetails' => function ($query) {
                    $query->select('user_id', 'first_name', 'last_name', 'mobile_no');
                }])
                ->get();
                
                return response()->json($users);
            }
         
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function login_audits(Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $audits = LoginAudits::select('id', 'email', 'date_time', 'ip_address', 'status', 'latitude', 'longitude')->get();
                return response()->json($audits);
            }
         
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function user_permissions($id,Request $request)
    {
         
        try {
            if ($request->isMethod('get')) {
                $user_role = User::where('id', $id)->value('role');
            
                $user_roles_array = json_decode($user_role, true) ?? [];
                
                if (empty($user_roles_array)) {
                    return response()->json(['status' => 'fail', 'permissions' => []]);
                }
            
                foreach ($user_roles_array as $user_roles_arra) {
                    $permissions = Roles::where('id', $user_roles_arra)->value('permissions');
                    if ($permissions !== null) {
                        return response()->json($permissions);
                    }
                }
            
                return response()->json(['status' => 'fail', 'permissions' => []]);
            }
            
            
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function role_user(Request $request)
    {
         
        try {
           
            if($request->isMethod('post')){

           
            $validator = Validator::make($request->all(), [
                'user' => 'required',
                'role' => 'required'
            ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            $existingRoles = User::where('id',$request->user)->first();
            $existingRolesArray = json_decode($existingRoles->role, true) ?? [];
            $roleData = is_string($request->role)
            ? json_decode($request->role, true)
            : $request->role;

            $newRolesArray = array_unique(array_merge($existingRolesArray, $roleData));

            $user = User::find($request->user);
            $user->role =$newRolesArray;
            $user->update();


            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('user role updated','user', $userId, $request->user, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Role Changed'
            ], 201);
        }
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function users_by_role($id, Request $request)
{
    try {
        if ($request->isMethod('get')) {

            $usersWithRole = User::select('id', 'email', 'role')
                ->whereJsonContains('role', $id)
                ->with(['userDetails' => function ($query) {
                    $query->select('user_id', 'first_name', 'last_name', 'mobile_no');
                }])
                ->get();

            $usersWithoutRole = User::select('id', 'email', 'role')
                ->whereNot(function ($query) use ($id) {
                    $query->whereJsonContains('role', $id);
                })
                ->with(['userDetails' => function ($query) {
                    $query->select('user_id', 'first_name', 'last_name', 'mobile_no');
                }])
                ->get();

            return response()->json([
                'users_with_role' => $usersWithRole,
                'users_without_role' => $usersWithoutRole,
            ]);
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function remove_role_user(Request $request)
{
    try {
        if ($request->isMethod('post')) {
            $validator = Validator::make($request->all(), [
                'user' => 'required',
                'role' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::find($request->user);

            if (!$user) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'User not found'
                ], 404);
            }

            $existingRolesArray = json_decode($user->role, true) ?? [];

            $roleData = is_string($request->role)
                ? json_decode($request->role, true)
                : $request->role;

            if (!is_array($roleData)) {
                $roleData = [$roleData];
            }

            $newRolesArray = array_diff($existingRolesArray, $roleData);

            $user->role = json_encode(array_values($newRolesArray));
            $user->update();
            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('user role removed','user', $userId, $request->user, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Role removed successfully'
            ], 200);
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function forgot_password(Request $request)
    {

        if ($request->isMethod('post')) {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            if (User::where("email", $request->email)->exists()) {
                $login_details = User::where('email', $request->email)->first();
            } else {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Please insert a valid email',
                ], 500);
            }

            $details  = [
                'body' => "Please Click On The Link Below To Rest Your Password",
                'link1' => Crypt::encryptString($login_details->id),
                'link2' => "reset",


            ];
            Mail::to($request->email)->send(new \App\Mail\ForgotPassword($details));

            return response()->json([
                'status' => "success",
                'message' => 'Password reset link has been sent to your email'
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }
        }
    }
    public function reset_password(Request $request)
    {

        if ($request->isMethod('post')) {
            try {
            $user_id = Crypt::decryptString($request->id);

            $validator = Validator::make($request->all(), [
               "password" => "required | min:6 | confirmed",
               "password_confirmation" => "required",
               "id" => "required",
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }
            $password = User::find($user_id);
            $password->password = Hash::make($request->input('password'));
            $password->update();
            return response()->json([
                'status' => "success",
                'message' => 'Password reset successful'
            ], 200);
        }
            catch (\Exception $e) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Request failed',
                    'error' => $e->getMessage()
                ], 500);
            }
        }

    }
    public function get_supervisors(Request $request)
{
    try {
        if ($request->isMethod('get')) {
            $supervisors = User::with(['userDetails'])->get()->map(function ($supervisor) {
                $roles = json_decode($supervisor->role, true);

                if (empty($roles) || !isset($roles[0])) {
                    return null;
                }

                $supervisor->first_role = $roles[0];

                // get role with permissions
                $role = Roles::where('id', $supervisor->first_role)->first();

                if (!$role || empty($role->permissions)) {
                    return null;
                }

                // decode permissions JSON
                $permissions = json_decode($role->permissions, true);

                // check if "Approve Documents" group exists
                $hasApproveDocs = collect($permissions)->contains(function ($perm) {
                    return isset($perm['group']) && $perm['group'] === 'Approve Documents';
                });

                if (!$hasApproveDocs) {
                    return null; // skip this supervisor
                }

                // attach role info if needed
                // $supervisor->role_details = $role;
                return $supervisor;
            })->filter();

            return response()->json($supervisors->values()); // reindex
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

}
