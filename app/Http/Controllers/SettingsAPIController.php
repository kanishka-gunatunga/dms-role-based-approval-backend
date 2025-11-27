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
use App\Models\Sectors;
use App\Models\SMTPDetails;
use App\Models\CompanyProfile;


class SettingsAPIController extends Controller
{

   
    public function add_smtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'host' => 'required',
                'port' => 'required',
                'user_name' => 'required',
                'password' => 'required',
                'from_name' => 'required',
                'encryption' => 'required',
                'is_default' => 'required',
            ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            if($request->is_default == '1'){
                $default_smtps = SMTPDetails::where('is_default', '1')->get();
                foreach($default_smtps as $default_smtp){
                    $default =  SMTPDetails::where('id',  $default_smtp->id)->first();;
                    $default->is_default = 0;
                    $default->update();
                }
            }
            $smtp = new SMTPDetails();
            $smtp->host = $request->host;
            $smtp->port = $request->port;
            $smtp->user_name = $request->user_name;
            $smtp->password = $request->password;
            $smtp->from_name = $request->from_name;
            $smtp->encryption = $request->encryption;
            $smtp->is_default = $request->is_default;
            $smtp->save();
    
            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('new smtp added','smtp', $userId, $smtp->id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'SMTP Details added.'
            ], 201);

        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function smtp_details($id,Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $smtp = SMTPDetails::where('id', $id)->first();
                return response()->json($smtp);
            }
            if($request->isMethod('post')){

           
                $validator = Validator::make($request->all(), [
                    'host' => 'required',
                    'port' => 'required',
                    'user_name' => 'required',
                    'password' => 'required',
                    'from_name' => 'required',
                    'encryption' => 'required',
                    'is_default' => 'required',
                ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            

            
            $smtp =  SMTPDetails::where('id',  $id)->first();;
            $smtp->host = $request->host;
            $smtp->port = $request->port;
            $smtp->user_name = $request->user_name;
            $smtp->password = $request->password;
            $smtp->from_name = $request->from_name;
            $smtp->encryption = $request->encryption;
            $smtp->is_default = $request->is_default;
            $smtp->update();


            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('smtp details updated','smtp', $userId, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Sector updated'
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

    public function delete_smtp($id,Request $request)
    {
         
        try {

            SMTPDetails::where('id', $id)->delete(); 

            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('smtp removed','smtp', $userId, $id, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'SMTP Details Deleted'
            ], 201);
        
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }

    public function all_smtps(Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $smtps = SMTPDetails::select('id', 'user_name', 'host', 'port', 'is_default')->get();
                return response()->json($smtps);
            }
         
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function company_profile(Request $request)
    {
        try {
            if($request->isMethod('get')){
                $company_profile = CompanyProfile::first();
                $company_profile->logo_url =asset('uploads/company_profile/'.$company_profile->logo.'');
                $company_profile->banner_url =asset('uploads/company_profile/'.$company_profile->banner.'');
                return response()->json($company_profile);
            }
            if($request->isMethod('post')){

            $validator = Validator::make($request->all(), [
                'logo' => 'nullable|file|mimes:jpg,png,webp',
                'banner' => 'nullable|file|mimes:jpg,png,webp',
            ]);
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            $company_profile=CompanyProfile::first();

            if($company_profile){
                if($request->logo == null){
                $logo_name = CompanyProfile::where('id',$company_profile->id)->value('logo');
                }
                else{
                $logo_name = time().'-logo-.'.$request->logo->extension();
                $request->logo->move(public_path('uploads/company_profile'), $logo_name);
                }
                if($request->banner == null){
                $banner_name = CompanyProfile::where('id',$company_profile->id)->value('banner');
                }
                else{
                $banner_name = time().'-banner-.'.$request->banner->extension();
                $request->banner->move(public_path('uploads/company_profile'), $banner_name);
                }
                if($request->title == null){
                $title = CompanyProfile::where('id',$company_profile->id)->value('title');
                }
                else{
                $title = $request->title;
                }
                if($request->enable_external_file_view == null){
                $enable_external_file_view = CompanyProfile::where('id',$company_profile->id)->value('enable_external_file_view');
                }
                else{
                $enable_external_file_view = $request->enable_external_file_view;
                }

                if($request->preview_file_extension == null){
                $preview_file_extension = CompanyProfile::where('id',$company_profile->id)->value('preview_file_extension');
                }
                else{
                $preview_file_extension = ltrim($request->preview_file_extension, '.');
                }

                if($request->enable_ad_login == null){
                    $enable_ad_login = CompanyProfile::where('id',$company_profile->id)->value('enable_ad_login');
                    }
                    else{
                    $enable_ad_login = $request->enable_ad_login;
                    }

                $profile = CompanyProfile::where('id',$company_profile->id)->first();
                $profile->title = $title;
                $profile->logo = $logo_name ;
                $profile->banner = $banner_name;
                $profile->enable_external_file_view = $enable_external_file_view;
                $profile->enable_ad_login = $enable_ad_login;
                $profile->preview_file_extension = $preview_file_extension;
                $profile->update();
            }
            else{
                if($request->logo == null){
                $logo_name =  null;
                }
                else{
                $logo_name = time().'-logo-.'.$request->logo->extension();
                $request->logo->move(public_path('uploads/company_profile'), $logo_name);
                }
                if($request->banner == null){
                $banner_name =  null;
                }
                else{
                $banner_name = time().'-banner-.'.$request->banner->extension();
                $request->banner->move(public_path('uploads/company_profile'), $banner_name);
                }
    
                $profile = new CompanyProfile();
                $profile->title = $request->title;
                $profile->logo = $logo_name ;
                $profile->banner = $banner_name;
                $profile->enable_external_file_view = $request->enable_external_file_view;
                $profile->enable_ad_login = $request->enable_ad_login;
                $profile->save();
            }
            
            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('company profile updated','company', $userId, null, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Company Profile Updated'
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
    public function company_profile_storage(Request $request)
    {
        try {

            if($request->isMethod('post')){
        
            $company_profile=CompanyProfile::first();

            if($company_profile){
                if($request->storage == null){
                $storage = CompanyProfile::where('id',$company_profile->id)->value('storage');
                }
                else{
                $storage = $request->storage;
                }
                if($request->key == null){
                $key = CompanyProfile::where('id',$company_profile->id)->value('key');
                }
                else{
                $key = $request->key;
                }
                if($request->secret == null){
                $secret = CompanyProfile::where('id',$company_profile->id)->value('secret');
                }
                else{
                $secret = $request->secret;
                }
                if($request->bucket == null){
                $bucket = CompanyProfile::where('id',$company_profile->id)->value('bucket');
                }
                else{
                $bucket = $request->bucket;
                }
                if($request->region == null){
                $region = CompanyProfile::where('id',$company_profile->id)->value('region');
                }
                else{
                $region = $request->region;
                }

                $profile = CompanyProfile::where('id',$company_profile->id)->first();
                $profile->storage = $storage;
                $profile->key = $key ;
                $profile->secret = $secret;
                $profile->bucket = $bucket;
                $profile->region = $region;
                $profile->update();
            }
            else{
    
                $profile = new CompanyProfile();
                $profile->storage = $storage;
                $profile->key = $key ;
                $profile->secret = $secret;
                $profile->bucket = $bucket;
                $profile->region = $region;
                $profile->save();
            }
            
    
            return response()->json([
                'status' => "success",
                'message' => 'Storage Details Updated'
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
    public function get_ad_connection(Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $ad_connection = CompanyProfile::first();
                if($ad_connection->enable_ad_login == 1){
                    $ad_login_status= 1;
                }
                else{
                    $ad_login_status= 0;
                }
                return response()->json($ad_login_status);
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
