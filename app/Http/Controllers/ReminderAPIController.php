<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth; 
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;


use App\Models\LoginAudits;
use App\Models\User;
use App\Models\UserDetails;
use App\Models\Categories;
use App\Models\Roles;
use App\Models\Documents;
use App\Models\DocumentSharedRoles;
use App\Models\DocumentSharedUsers;
use App\Models\DocumentSharedLinks;
use App\Models\DocumentVersions;
use App\Models\DocumentComments;
use App\Models\Reminders;

use App\Http\Controllers\CommonFunctionsController;
use App\Models\DocumentAuditTrial;
use Mail;

class ReminderAPIController extends Controller
{

public function reminder(Request $request)
    {
         
        try {

            if($request->isMethod('post')){

                $validator = Validator::make($request->all(), [
                    'subject' => 'required',
                    'message' => 'required',
                    'frequency_details' => 'nullable',
                    'users' => 'nullable',
                ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }


            $doc_reminder = new Reminders();
            $doc_reminder->document_id = $request->document_id;
            $doc_reminder->subject = $request->subject;
            $doc_reminder->message = $request->message;
            $doc_reminder->date_time = $request->date_time;
            $doc_reminder->is_repeat = $request->is_repeat;
            $doc_reminder->send_email = $request->send_email;
            $doc_reminder->frequency = $request->frequency;
            $doc_reminder->end_date_time = $request->end_date_time;
            $doc_reminder->start_date_time = $request->start_date_time;
            $doc_reminder->frequency_details = $request->frequency_details;
            $doc_reminder->users = $request->users;
            $doc_reminder->roles = $request->roles;
            $doc_reminder->save();
            
            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('new reminder added','reminder', $userId, $doc_reminder->id, $date_time, null, null);

            if ($request->send_email) {
                $recipientEmails = collect();

                // (1) Direct selected users
                if (!empty($request->users)) {
                    $userIds = is_array($request->users) ? $request->users : json_decode($request->users, true);
                    if (!empty($userIds)) {
                        $users = \App\Models\User::whereIn('id', $userIds)->pluck('email');
                        $recipientEmails = $recipientEmails->merge($users);
                    }
                }

                // (2) Users belonging to selected roles
                if (!empty($request->roles)) {
                    $roleIds = is_array($request->roles) ? $request->roles : json_decode($request->roles, true);
                    if (!empty($roleIds)) {
                        $roleUsers = \App\Models\User::where(function ($query) use ($roleIds) {
                            foreach ($roleIds as $roleId) {
                                $query->orWhereJsonContains('role', $roleId);
                            }
                        })->pluck('email');

                        $recipientEmails = $recipientEmails->merge($roleUsers);
                    }
                }

                // Remove duplicates
                $recipientEmails = $recipientEmails->unique()->filter();

                // Send emails
                if ($recipientEmails->isNotEmpty()) {
                    $details = [
                        'subject' => $request->subject,
                        'message' => $request->message,
                        'date_time' => $request->date_time,
                    ];

                    foreach ($recipientEmails as $email) {
                        Mail::to($email)->send(new \App\Mail\ReminderMail($details));
                    }
                }
            }
            
            return response()->json([
                'status' => "success",
                'message' => 'Reminder Added',
                // 'recipientEmails' => $recipientEmails
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
    public function edit_reminder($id,Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $reminders = Reminders::where('id', $id)->first();
                if (!empty($reminders->users) && is_array(json_decode($reminders->users, true))) { 
                $userIds = json_decode($reminders->users, true);

                $users = UserDetails::whereIn('user_id', $userIds)
                    ->get(['user_id', 'first_name', 'last_name'])
                    ->mapWithKeys(function ($user) {
                        return [$user->user_id => $user->first_name . ' ' . $user->last_name];
                    })
                    ->toArray();
            
                $usersWithNames = array_map(function ($userId) use ($users) {
                    return [
                        'id' => $userId,
                        'name' => $users[$userId] ?? 'Unknown'
                    ];
                }, $userIds);
            
                $reminders->users = $usersWithNames;
                }
                
                if (!empty($reminders->roles) && is_array(json_decode($reminders->roles, true))) { 
                $roleIds = json_decode($reminders->roles, true);

                $roles = Roles::whereIn('id', $roleIds)
                    ->get(['id', 'role_name'])
                    ->mapWithKeys(function ($role) {
                        return [$role->id => $role->role_name];
                    })
                    ->toArray();
            
                $rolesWithNames = array_map(function ($roleId) use ($roles) {
                    return [
                        'id' => $roleId,
                        'role_name' => $roles[$roleId] ?? 'Unknown'
                    ];
                }, $roleIds);
            
                $reminders->roles = $rolesWithNames;
                }

                return response()->json($reminders);
            }
            if($request->isMethod('post')){

           
                $validator = Validator::make($request->all(), [
                    'subject' => 'required',
                    'message' => 'required',
                    'frequency_details' => 'nullable',
                    'users' => 'nullable',
                ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            

            
            $doc_reminder =  Reminders::where('id', $id)->first();;
            $doc_reminder->document_id = $request->document_id;
            $doc_reminder->subject = $request->subject;
            $doc_reminder->message = $request->message;
            $doc_reminder->date_time = $request->date_time;
            $doc_reminder->is_repeat = $request->is_repeat;
            $doc_reminder->send_email = $request->send_email;
            $doc_reminder->frequency = $request->frequency;
            $doc_reminder->end_date_time = $request->end_date_time;
            $doc_reminder->start_date_time = $request->start_date_time;
            $doc_reminder->frequency_details = $request->frequency_details;
            $doc_reminder->users = $request->users;
            $doc_reminder->roles = $request->roles;
            $doc_reminder->update();


            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('reminder details updated','reminder', $userId, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Reminder updated'
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
    public function reminders(Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $reminders = Reminders::with(['document' => function ($query) {
                    $query->select('id', 'name');
                }])->orderBy('id', 'DESC')->get();

                foreach($reminders as $reminder){
                    if($reminder->start_date_time == null){
                        $reminder->start_date_time = $reminder->date_time;
                    }
                    if($reminder->end_date_time == null){
                        $reminder->end_date_time = $reminder->date_time;
                    }
                }
                return response()->json($reminders);
            }
           
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }

    public function reminders_user(Request $request)
{
    try {
        if ($request->isMethod('get')) {
            $userId = auth('api')->id();
            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'status' => "fail",
                    'message' => 'Invalid User'
                ], 404);
            }

            // Check if user is super_admin
            if ($user->user_type === 'super_admin') {
                $reminders = Reminders::with(['document' => function ($query) {
                    $query->select('id', 'name');
                }])->orderBy('id', 'DESC')->get();
            } else {
                // Decode user's roles (stored as JSON in DB)
                $userRoles = [];
                if (!empty($user->role)) {
                    $userRoles = json_decode($user->role, true);
                }

                // Load reminders for this user (by id or role)
                $reminders = Reminders::with(['document' => function ($query) {
                    $query->select('id', 'name');
                }])
                    ->where(function ($q) use ($userId, $userRoles) {
                        $q->whereRaw("JSON_CONTAINS(users, '\"$userId\"')");

                        if (!empty($userRoles)) {
                            foreach ($userRoles as $role) {
                                $q->orWhereRaw("JSON_CONTAINS(roles, '\"$role\"')");
                            }
                        }
                    })
                    ->orderBy('id', 'DESC')
                    ->get();
            }

            // Normalize start_date_time & end_date_time
            foreach ($reminders as $reminder) {
                if ($reminder->start_date_time == null) {
                    $reminder->start_date_time = $reminder->date_time;
                }
                if ($reminder->end_date_time == null) {
                    $reminder->end_date_time = $reminder->date_time;
                }
            }

            return response()->json($reminders);
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}


    public function delete_reminder($id,Request $request)
    {
         
        try {
            Reminders::where('id', '=', $id)->delete();

            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('reminder removed','reminder', $userId, $id, $date_time, null, null);

                return response()->json([
                    'status' => "success",
                    'message' => 'Reminder Deleted'
                ], 201);
            
        
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function filter_reminders(Request $request)
    {
         
        try {
            if($request->isMethod('post')){
                $reminders = Reminders::with(['document' => function ($query) {
                    $query->select('id', 'name');
                }]);
                
                if ($request->has('subject') && $request->subject) {
                    $reminders = $reminders->where('subject', 'LIKE', "%{$request->subject}%");
                }
                
                if ($request->has('message') && $request->message) {
                    $reminders = $reminders->where('message', 'LIKE', "%{$request->message}%");
                }
                
                if ($request->has('frequency') && $request->frequency) {
                    $reminders = $reminders->where('frequency', $request->frequency);
                }
                
                $reminders = $reminders->orderBy('id', 'DESC')->get();
                
                foreach($reminders as $reminder){
                    if($reminder->start_date_time == null){
                        $reminder->start_date_time = $reminder->date_time;
                    }
                    if($reminder->end_date_time == null){
                        $reminder->end_date_time = $reminder->date_time;
                    }
                }
                
                return response()->json($reminders);
                
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