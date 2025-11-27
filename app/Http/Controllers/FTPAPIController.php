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

use App\Models\LoginAudits;
use App\Models\User;
use App\Models\UserDetails;
use App\Models\Categories;
use App\Models\Sectors;
use App\Models\FTPAccounts;
use App\Models\Documents;


class FTPAPIController extends Controller
{

   
    public function add_ftp_account(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'host' => 'required',
                'port' => 'required',
                'root_path' => 'required',
            ]);
        
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }

            if($request->is_default == 1){
            FTPAccounts::where('is_default', 1)
            ->update(['is_default' => 0]);
            }

            $ftp = new FTPAccounts();
            $ftp->name = $request->name;
            $ftp->host = $request->host;
            $ftp->port = $request->port;
            $ftp->username = $request->username;
            $ftp->password = $request->password;
            $ftp->root_path = $request->root_path;
            $ftp->is_default = $request->is_default;
            $ftp->save();
    
            return response()->json([
                'status' => "success",
                'message' => 'FTP Account added.'
            ], 201);

        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function ftp_accounts(Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $ftp_accounts = FTPAccounts::get();
                return response()->json($ftp_accounts);
            }
         
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function edit_ftp_account($id,Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $ftp_accounts = FTPAccounts::where('id', $id)->first();
                return response()->json($ftp_accounts);
            }
            if($request->isMethod('post')){

                $validator = Validator::make($request->all(), [
                    'host' => 'required',
                    'port' => 'required',
                    'root_path' => 'required',
                ]);
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422); 
            }
            
            $ftp =  FTPAccounts::where('id',  $id)->first();;
            $ftp->name = $request->name;
            $ftp->host = $request->host;
            $ftp->port = $request->port;
            $ftp->username = $request->username;
            $ftp->password = $request->password;
            $ftp->root_path = $request->root_path;
            $ftp->update();

            return response()->json([
                'status' => "success",
                'message' => 'FTP account updated'
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

    public function delete_ftp_account($id,Request $request)
    {
         
        try {

            FTPAccounts::where('id', $id)->delete(); 

            return response()->json([
                'status' => "success",
                'message' => 'FTP Account Deleted'
            ], 201);
        
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }

public function view_ftp_file()
{
    $ftpAccount = FTPAccounts::findOrFail(1);
    $document_details = Documents::where('id',51)->first();
    
    config([
        'filesystems.disks.dynamic_ftp' => [
            'driver' => 'ftp',
            'host' => $ftpAccount->host,
            'username' => $ftpAccount->username,
            'password' => $ftpAccount->password,
            'port' => (int) $ftpAccount->port,
            'root' => ltrim($ftpAccount->root_path, '/'),
        ],
    ]);

    $disk = Storage::disk('dynamic_ftp');
    $filePath = $document_details->file_path;
    if (!$disk->exists($filePath)) {
        return response()->json(['message' => 'File not found on the FTP server'], 404);
    }

    return response()->streamDownload(function () use ($disk, $filePath) {
        echo $disk->get($filePath);
    }, basename($filePath));
}
}
