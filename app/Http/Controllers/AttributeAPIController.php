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
use App\Http\Controllers\CommonFunctionsController;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Maatwebsite\Excel\Facades\Excel;

use App\Models\LoginAudits;
use App\Models\User;
use App\Models\UserDetails;
use App\Models\Categories;
use App\Models\Sectors;
use App\Models\Attribute;

class AttributeAPIController extends Controller
{

   
    public function add_attribute(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'category' => 'required',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => "fail",
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);  
        }

        $headers = [['name', 'description', 'meta_tags']];

        $existingAttribute = Attribute::where('category', $request->category)->first(); 

        if ($existingAttribute) {
            $existingAttributesArray = json_decode($existingAttribute->attributes, true) ?? [];

            $attributeData = is_string($request->attribute_data)
                ? json_decode($request->attribute_data, true)
                : $request->attribute_data;

            $newAttributesArray = array_values(array_unique(array_merge($existingAttributesArray, $attributeData)));

            $existingAttribute->attributes = json_encode($newAttributesArray);
            $existingAttribute->save();
        }  else {
            $attribute = new Attribute();
            $attribute->category = $request->category;

            $attributeData = is_string($request->attribute_data)
                ? json_decode($request->attribute_data, true)
                : $request->attribute_data;

            $attribute->attributes = json_encode($attributeData);
            $attribute->save();
        }

        $updatedAttribute = Attribute::where('category', $request->category)->first();
            if ($updatedAttribute) {
                $attributeData = json_decode($updatedAttribute->attributes, true);

                foreach ($attributeData as $key => $value) {
                    if (is_string($value)) {
                        $headers[0][] = $value; 
                    }
                }
            }

            $fileName = 'category_template_' . $request->category . '_' . time() . '.xlsx';
            $filePath = 'excel_templates/' . $fileName;
            
            Excel::store(new class($headers) implements \Maatwebsite\Excel\Concerns\FromArray {
                private $data;
                public function __construct(array $data)
                {
                    $this->data = $data;
                }

                public function array(): array
                {
                    return $this->data;
                }
            }, $filePath, 'public');

            $storagePath = storage_path('app/public/' . $filePath);
            $destinationPath = public_path('uploads/excel_templates');
            
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            
            $destinationFilePath = $destinationPath . '/' . $fileName;
            
            rename($storagePath, $destinationFilePath);

            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('new attribute added','category', $userId, $request->category, $date_time, null, null);

            $category = Categories::where('id', '=', $request->category)->first();
            $category->parent_category = $request->parent_category;
            $category->category_name = $request->category_name;
            $category->description = $request->description;
            $category->template = $fileName;  
            $category->update();

        return response()->json([
            'status' => "success",
            'message' => 'Attribute added.'
        ], 201);

    } catch (\Exception $e) {
 

        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }    
}


    public function attribute_details($id,Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $attribute = Attribute::where('id', $id)->with('category')->first();
                return response()->json($attribute);
            }
            if($request->isMethod('post')){

                $validator = Validator::make($request->all(), [
                    'category' => 'required',
                ]);
        
            if ($validator->fails()) {
                return response()->json([
                     'status' => "fail",
                    'message' => 'Validation errors',
                    'errors' => $validator->errors() 
                ], 422); 
            }
            $existingAttribute = Attribute::where('id', $id)->first();
            

            $existingAttributesArray = json_decode($existingAttribute->attributes, true) ?? [];

        
            $attributeData = is_string($request->attribute_data)
                ? json_decode($request->attribute_data, true)
                : $request->attribute_data;

            $newAttributesArray = array_values(array_unique(array_merge($existingAttributesArray, $attributeData)));

            $existingAttribute->category = $request->category;
            $existingAttribute->attributes = $newAttributesArray;
            $existingAttribute->update();
            
            // $attribute =  Attribute::where('id',  $id)->first();
            // $attribute->category = $request->category;
            // $attribute->attributes = $request->attribute_data;
            // $attribute->update();

            $userId = auth('api')->id();

            $date_time = Carbon::now()->format('Y-m-d H:i:s');
            $auditFunction = new CommonFunctionsController();
            $auditFunction->document_audit_trail('attribute details updated','category', $userId, $request->category, $date_time, null, null);

            return response()->json([
                'status' => "success",
                'message' => 'Attribute updated'
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

    public function delete_attribute($id,Request $request)
    {
         
        try {

            Attribute::where('id', $id)->delete(); 

            return response()->json([
                'status' => "success",
                'message' => 'Attribute Deleted'
            ], 201);
        
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }

    public function attributes(Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $attributes = Attribute::select('id', 'category', 'attributes')->with('category')->get();
                return response()->json($attributes);
            }
         
        } catch (\Exception $e) {

            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function attribute_by_category($id,Request $request)
    {
         
        try {
            if($request->isMethod('get')){
                $attribute = Attribute::where('category', $id)->with('category')->first();
                return response()->json($attribute);
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
