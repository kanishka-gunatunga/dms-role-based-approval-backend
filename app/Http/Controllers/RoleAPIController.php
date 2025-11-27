<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth; 
use Illuminate\Validation\Rules\Password;
use Carbon\Carbon;
use App\Http\Controllers\CommonFunctionsController;

use App\Models\LoginAudits;
use App\Models\User;
use App\Models\UserDetails;
use App\Models\Categories;
use App\Models\Roles;

class RoleAPIController extends Controller
{

   
    public function add_role(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'role_name' => 'required',
                'permissions' => 'nullable',
            ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

 
            $role = new Roles();
            $role->role_name = $request->role_name;
            $role->permissions = $request->permissions;
            $role->needs_approval = $request->needs_approval;
            $role->save();
    
            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('new role added','role', $userId, $role->id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Role added.'
            ], 201);

        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function role_details($id,Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $role = Roles::where('id', $id)->first();
                return response()->json($role);
            }
            if($request->isMethod('post')){

           
                $validator = Validator::make($request->all(), [
                    'role_name' => 'required',
                    'permissions' => 'nullable',
                ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            

            
            $role =  Roles::where('id', '=', $id)->first();;
            $role->role_name = $request->role_name;
            $role->permissions = $request->permissions;
            $role->needs_approval = $request->needs_approval;
            $role->update();

            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('role details updated','role', $userId, $id, $date_time, null, null);


            return response()->json([
                'status' => "success",
                'message' => 'Role updated'
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

    public function delete_role($id,Request $request)
    {
         
        try {

            Roles::where('id', $id)->delete(); 

            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('role removed','role', $userId, $id, $date_time, null, null);
            
            return response()->json([
                'status' => "success",
                'message' => 'Role Deleted'
            ], 201);
        
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }

    public function roles(Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $roles = Roles::select('id', 'role_name', 'permissions', 'needs_approval')->get();
                return response()->json($roles);
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
