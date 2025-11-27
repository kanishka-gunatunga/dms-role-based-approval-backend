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
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use OpenAI\Laravel\Facades\OpenAI;
use Google\Cloud\Translate\V2\TranslateClient;
use App\Models\LoginAudits;
use App\Models\User;
use App\Models\UserDetails;
use App\Models\Categories;
use App\Models\Sectors;
use App\Models\Attribute;
use App\Models\ADCredential;
use App\Models\Documents;
use App\Models\FTPAccounts;
use App\Models\CompanyProfile;
use MeiliSearch\Client;

class AIController extends Controller
{

   
    // public function initialize_chat($id,Request $request)
    // {
    //     try {
    //         $company_profile = CompanyProfile::first();

    //         $send_all_to_gpt = $company_profile->send_all_to_gpt;
    //         $send_all_to_pinecone = $company_profile->send_all_to_pinecone;
    //         $set_page_limit = $company_profile->set_page_limit;
    //         $pages_count = $company_profile->pages_count;

    //         $document = Documents::where('id', $id)->first();
    //         $chatId = time() . rand(100000, 999999);
            
    //             $documentContext = '';
                
    //             $retreiveDocumentPath = new CommonFunctionsController();
    //             $file_path_full = $retreiveDocumentPath->retreive_document_path($document);

    //             $getFileTextContent = new CommonFunctionsController();
    //             $documentContext = $getFileTextContent->sendToNodeGetFullText($file_path_full);

    //             $chunks = $this->chunkText($documentContext['content'] ?? '');
    
    //             if (empty($chunks)) {
    //                 return response()->json([
    //                     'status' => 'error',
    //                     'message' => 'Document content is empty or could not be chunked.',
    //                 ], 400);
    //             }
                
    //             foreach ($chunks as $index => $chunk) {
    //                 // Get embedding from OpenAI
    //                 $response = OpenAI::embeddings()->create([
    //                     'model' => 'text-embedding-ada-002',
    //                     'input' => $chunk,
    //                 ]);
                    
    //                 $embedding = $response->embeddings[0]->embedding;
                    
    //                 $record = [
    //                     'id' => "{$chatId}_{$index}",
    //                     'values' => $embedding,
    //                     'metadata' => [
    //                         'chunk_text' => $chunk,
    //                         'chatId' => $chatId,
    //                         'docId' => "doc_id_{$chatId}",
    //                     ]
    //                 ];
                    
    //                 $this->upsertToPinecone([$record]);
                    
    //                 \Log::info("Upserted record: {$chatId}_{$index}");
    //             }
                
    //         return response()->json([
    //             'status' => "success",
    //             'message' => 'Document added.',
    //             'chat_id' => $chatId
    //         ], 201);
    
            
    //     } catch (\Exception $e) {
    
    //         return response()->json([
    //             'status' => "fail",
    //             'message' => 'Request failed',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }    
    // }
    public function initialize_chat($id, Request $request)
{
    try {
        $company_profile = CompanyProfile::first();

       
        $send_all_to_gpt = $company_profile->send_all_to_gpt;
        $send_all_to_pinecone = $company_profile->send_all_to_pinecone;
        $set_page_limit = $company_profile->set_page_limit;
        $pages_count = $company_profile->pages_count;

        $document = Documents::where('id', $id)->first();
        $chatId = time() . rand(100000, 999999);

        $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
        $index = $client->index('python_test_docs_3');
        $searchResult = $index->search('', [
            'filter' => "sql_document = '{$document->id}'",
        ])->getHits();
        $documentPages = $searchResult ?? [];
  
        $documentPages = collect($documentPages)->sortBy('page')->values();
        
        $totalPages = $documentPages->count();

        $documentText = $documentPages->pluck('content')
        ->map(function($content) {
            return str_replace("\u000b", "\n", $content);
        })
        ->join("\n\n");
        
        // $retreiveDocumentPath = new CommonFunctionsController();
        // $file_path_full = $retreiveDocumentPath->retreive_document_path($document);

        // $getFileTextContent = new CommonFunctionsController();
        // $documentContext = $getFileTextContent->sendToNodeGetFullText($file_path_full);

        // $documentText = $documentContext['content'] ?? '';

        // return $documentContext;
        //  return response()->json([
        //         'status' => 'error',
        //         'message' => $documentText,
        //     ], 400);
        if (empty($documentText)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No content found for this document.',
            ], 400);
        }

        $usePinecone = false;

        if ($send_all_to_pinecone == 1) {
            $usePinecone = true;
        } elseif ($set_page_limit == 1 && $totalPages > $pages_count) {
            $usePinecone = true;
        }

        if ($usePinecone) {
            $chunks = $this->chunkText($documentText);
            if (empty($chunks)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Document content could not be chunked.',
                ], 400);
            }

            foreach ($chunks as $index => $chunk) {
                $response = OpenAI::embeddings()->create([
                    'model' => 'text-embedding-ada-002',
                    'input' => $chunk,
                ]);

                $embedding = $response->embeddings[0]->embedding;

                $record = [
                    'id' => "{$chatId}_{$index}",
                    'values' => $embedding,
                    'metadata' => [
                        'chunk_text' => $chunk,
                        'chatId' => $chatId,
                        'docId' => "doc_id_{$chatId}",
                    ]
                ];

                $this->upsertToPinecone([$record]);

                \Log::info("Upserted record: {$chatId}_{$index}");
            }

        } else {

            Cache::put("chat_doc_context_{$chatId}", $documentText, now()->addHours(1));
        }

        return response()->json([
            'status' => "success",
            'message' => 'Document processed.',
            'chat_id' => $chatId
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'status' => "fail",
            'message' => 'Request failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

private function chunkText(string $text, int $chunkSize = 6000): array
    {
        $chunks = [];
        for ($i = 0; $i < strlen($text); $i += $chunkSize) {
            $chunks[] = substr($text, $i, $chunkSize);
        }
        return $chunks;
    }

    public function upsertToPinecone(array $records)
    {
        $pineconeHost = env('PINECONE_HOST'); 
        $pineconeNamespace = env('PINECONE_NAMESPACE'); 
        $pineconeApiKey = env('PINECONE_API_KEY');
    
        $url = "{$pineconeHost}/vectors/upsert";
    
        $formattedVectors = collect($records)->map(function ($record) {
            return [
                'id' => $record['id'],
                'values' => $record['values'],
                'metadata' => $record['metadata'] ?? new \stdClass(), 
            ];
        })->values()->all();

        $payload = [
            'vectors' => $formattedVectors,
            'namespace' => $pineconeNamespace,
        ];

        $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        'Api-Key' => $pineconeApiKey,
        'X-Pinecone-API-Version' => '2025-01',
    ])->post($url, $payload);

    
        if ($response->successful()) {
            return $response->json();
        } else {
            // Log error or throw exception
            logger()->error('Pinecone upsert failed', ['response' => $response->body()]);
            throw new \Exception('Failed to upsert to Pinecone: ' . $response->body());
        }
    }


//     public function qa_chat(Request $request)
// {
//     $message = $request->input('message');
//     $chatId = $request->input('chat_id');

//     try {

//         $embeddingResponse = OpenAI::embeddings()->create([
//             'model' => 'text-embedding-ada-002',
//             'input' => $message,
//         ]);

//         $embedding = $embeddingResponse->embeddings[0]->embedding;

//         $pineconeHost = env('PINECONE_HOST');
//         $pineconeNamespace = env('PINECONE_NAMESPACE');
//         $pineconeApiKey = env('PINECONE_API_KEY');

//         $queryPayload = [
//             'vector' => $embedding,
//             'topK' => 50,
//             'includeMetadata' => true,
//             'namespace' => $pineconeNamespace,
//             'filter' => [
//                 'chatId' => [ '$eq' => $chatId ]
//             ]
//         ];

//         $response = Http::withHeaders([
//             'Content-Type' => 'application/json',
//             'Api-Key' => $pineconeApiKey,
//             'X-Pinecone-API-Version' => '2025-01',
//         ])->post("{$pineconeHost}/query", $queryPayload);

//         if (!$response->successful()) {
//             throw new \Exception("Pinecone query failed: " . $response->body());
//         }

//         $results = $response->json();

//         $combinedText = collect($results['matches'] ?? [])
//             ->pluck('metadata.chunk_text')
//             ->filter()
//             ->implode("\n");

//         $completion = OpenAI::chat()->create([
//             'model' => 'gpt-4o-mini', 
//             'temperature' => 0,
//             'messages' => [
//                 [
//                     'role' => 'system',
//                     'content' => "
// You are a precise assistant that only answers questions strictly using the provided document context below.

// Rules:
// - Do NOT generate or infer any information not explicitly present in the context.
// - You may engage in friendly greetings or short acknowledgments.
// - If the answer is not clearly stated in the context, respond with:
//   \"Sorry, I couldn't find any information on that in the provided document.\"

// Context:
// $combinedText
//                     ",
//                 ],
//                 [
//                     'role' => 'user',
//                     'content' => $message,
//                 ]
//             ]
//         ]);

//         return response()->json([
//             'status' => 'success',
//             'response' => $completion->choices[0]->message->content,
//         ]);

//     } catch (\Exception $e) {
//         \Log::error("Chat QA Error", ['error' => $e->getMessage()]);
//         return response()->json([
//             'status' => 'error',
//             'message' => 'Something went wrong',
//             'error' => $e->getMessage()
//         ], 500);
//     }
// }
public function qa_chat(Request $request)
{
    $message = $request->input('message');
    $chatId = $request->input('chat_id');

    try {
        // $company_profile = CompanyProfile::first();

        // $send_all_to_gpt = $company_profile->send_all_to_gpt;
        // $send_all_to_pinecone = $company_profile->send_all_to_pinecone;
        // $set_page_limit = $company_profile->set_page_limit;
        // $pages_count = $company_profile->pages_count;

        // $usePinecone = false;
        // if ($send_all_to_pinecone == 1) {
        //     $usePinecone = true;
        // } elseif ($set_page_limit == 1) {
        //     if ($document && $document->page_count > $pages_count) {
        //         $usePinecone = true;
        //     }
        // }

        if (Cache::get("chat_doc_context_{$chatId}")) {

            $cachedContext = Cache::get("chat_doc_context_{$chatId}");

            $documentContext = $cachedContext;
            
            $completion = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'temperature' => 0,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "
                            You are a helpful and articulate assistant that provides engaging, easy-to-understand answers based strictly on the provided document context below.

                            Objectives:
                            - Communicate clear, warm, and informative.
                            - Always stay true to the content; do not fabricate or add information that isn't in the provided context.
                            - It's okay to rephrase or paraphrase for clarity and readability, as long as the meaning remains accurate.
                            - Answer the question strictly based on the provided context. Do not use any external or public information. If the answer cannot be found within the given context, respond with: 'Answer not found in the given context.

                            HTML Formatting Rules:
                            - Use <h3> for major section titles and <h4> for relevant subsections.
                            - Use <p> for regular descriptive text.
                            - Use <ul><li>...</li></ul> for bulleted lists.
                            - Use <ol><li>...</li></ol> for numbered lists.
                            - Use <br> only where appropriate (e.g., greetings, contact blocks).
                            - Do NOT use markdown (e.g., **, #).
                            - Do NOT include <html>, <head>, <body>, or any boilerplate tags.
                            - Keep the HTML semantic, clean, and well-structured for rendering on a webpage.

                            Tone & Style:
                            - You may include light greetings or acknowledgments to make the response feel natural.
                            - Use a conversational tone while maintaining professionalism.
                            - Make the summary feel like you're explaining it to someone new in a friendly, intelligent way.

                            Context:
                            $documentContext
                        "

                        ],
                        [
                            'role' => 'user',
                            'content' => $message,
                        ]
            ]
            ]);

            return response()->json([
                'status' => 'success',
                'response' => $completion->choices[0]->message->content,
            ]);
        }

        $embeddingResponse = OpenAI::embeddings()->create([
            'model' => 'text-embedding-ada-002',
            'input' => $message,
        ]);

        $embedding = $embeddingResponse->embeddings[0]->embedding;

        $pineconeHost = env('PINECONE_HOST');
        $pineconeNamespace = env('PINECONE_NAMESPACE');
        $pineconeApiKey = env('PINECONE_API_KEY');

        $queryPayload = [
            'vector' => $embedding,
            'topK' => 50,
            'includeMetadata' => true,
            'namespace' => $pineconeNamespace,
            'filter' => [
                'chatId' => [ '$eq' => $chatId ]
            ]
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Api-Key' => $pineconeApiKey,
            'X-Pinecone-API-Version' => '2025-01',
        ])->post("{$pineconeHost}/query", $queryPayload);

        if (!$response->successful()) {
            throw new \Exception("Pinecone query failed: " . $response->body());
        }

        $results = $response->json();
        $combinedText = collect($results['matches'] ?? [])
            ->pluck('metadata.chunk_text')
            ->filter()
            ->implode("\n");

        $completion = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'temperature' => 0,
            'messages' => [
                    [
                        'role' => 'system',
                        'content' => "
                            You are a helpful and articulate assistant that provides engaging, easy-to-understand answers based strictly on the provided document context below.

                            Objectives:
                            - Communicate clear, warm, and informative.
                            - Always stay true to the content; do not fabricate or add information that isn't in the provided context.
                            - It's okay to rephrase or paraphrase for clarity and readability, as long as the meaning remains accurate.
                            - Answer the question strictly based on the provided context. Do not use any external or public information. If the answer cannot be found within the given context, respond with: 'Answer not found in the given context.

                            HTML Formatting Rules:
                            - Use <h3> for major section titles and <h4> for relevant subsections.
                            - Use <p> for regular descriptive text.
                            - Use <ul><li>...</li></ul> for bulleted lists.
                            - Use <ol><li>...</li></ol> for numbered lists.
                            - Use <br> only where appropriate (e.g., greetings, contact blocks).
                            - Do NOT use markdown (e.g., **, #).
                            - Do NOT include <html>, <head>, <body>, or any boilerplate tags.
                            - Keep the HTML semantic, clean, and well-structured for rendering on a webpage.

                            Tone & Style:
                            - You may include light greetings or acknowledgments to make the response feel natural.
                            - Use a conversational tone while maintaining professionalism.
                            - Make the summary feel like you're explaining it to someone new in a friendly, intelligent way.

                            Context:
                            $combinedText
                        "

                        ],
                        [
                            'role' => 'user',
                            'content' => $message,
                        ]
            ]

        ]);

        return response()->json([
            'status' => 'success',
            'response' => $completion->choices[0]->message->content,
        ]);

    } catch (\Exception $e) {
        \Log::error("Chat QA Error", ['error' => $e->getMessage()]);
        return response()->json([
            'status' => 'error',
            'message' => 'Something went wrong',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function delete_vectors(string $chatId): JsonResponse
{
    Cache::forget("chat_doc_context_{$chatId}");

    $pineconeHost = env('PINECONE_HOST');
    $pineconeNamespace = env('PINECONE_NAMESPACE');
    $pineconeApiKey = env('PINECONE_API_KEY');

    if (!$chatId) {
        return response()->json(['error' => 'Missing chatId'], 400);
    }

    try {

        $queryUrl = "{$pineconeHost}/query";

        $queryResponse = Http::withHeaders([
            'Api-Key' => $pineconeApiKey,
            'Content-Type' => 'application/json',
            'X-Pinecone-API-Version' => '2025-01',
        ])->post($queryUrl, [
            'namespace' => $pineconeNamespace,
            'vector' => array_fill(0, 1536, 0),
            'topK' => 100,
            'filter' => [
                'docId' => ['$eq' => "doc_id_{$chatId}"]
            ],
            'includeValues' => false,
        ]);

        if (!$queryResponse->successful()) {
            logger()->error('Failed to query Pinecone for vectors', ['response' => $queryResponse->body()]);
            return response()->json(['error' => 'Failed to query Pinecone'], 500);
        }

        $results = $queryResponse->json();
        $idsToDelete = collect($results['matches'] ?? [])->pluck('id')->toArray();

        if (!empty($idsToDelete)) {
            $deleteUrl = "{$pineconeHost}/vectors/delete";
            $deleteResponse = Http::withHeaders([
                'Api-Key' => $pineconeApiKey,
                'Content-Type' => 'application/json',
                'X-Pinecone-API-Version' => '2025-01',
            ])->post($deleteUrl, [
                'ids' => $idsToDelete,
                'namespace' => $pineconeNamespace,
            ]);

            if (!$deleteResponse->successful()) {
                logger()->error('Failed to delete vectors from Pinecone', ['response' => $deleteResponse->body()]);
                return response()->json(['error' => 'Failed to delete vectors'], 500);
            }

            return response()->json(['deleted' => count($idsToDelete)]);
        }

        return response()->json(['status' => 'success']);

    } catch (\Exception $e) {
        logger()->error('Error during vector deletion', ['error' => $e->getMessage()]);
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}

public function summarize_document($id,Request $request)
    {
        try {
            $document = Documents::where('id', $id)->first();
            
            // $retreiveDocumentPath = new CommonFunctionsController();
            // $file_path_full = $retreiveDocumentPath->retreive_document_path($document);
                
                // $getFileTextContent = new CommonFunctionsController();
                // $documentContext = $getFileTextContent->sendToNodeGetFullText($file_path_full);
                // $context= $documentContext['content'];
                $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
                $index = $client->index('python_test_docs_3');
                $searchResult = $index->search('', [
                    'filter' => "sql_document = '{$document->id}'",
                ])->getHits();
                $documentPages = $searchResult ?? [];
        
                $documentPages = collect($documentPages)->sortBy('page')->values();

                $context = $documentPages->pluck('content')
                ->map(function($content) {
                    return str_replace("\u000b", "\n", $content);
                })
                ->join("\n\n");
               
                $completion = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini', 
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' =>"
                            You are a strict summarization assistant.

                            Your only task is to summarize the provided context when asked. You must not answer questions or perform any tasks outside of summarization.

                            Instructions:
                            1. Format your response clearly using:
                            - <h3> for section headings
                            - <ul> and <li> for bullet points
                            - No <head> or <body> tags

                            Always use the following context for summarization:

                            Context:
                            $context
                            ",
                        ],
                        [
                            'role' => 'user',
                            'content' => 'give a summery of this context',
                        ],
                    ]
                ]);
        
                return response()->json([
                    'status' => 'success',
                    'response' => $completion->choices[0]->message->content,
                ]);

        } catch (\Exception $e) {
    
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }

    // public function translate_document(Request $request)
    // {
    //     $language = $request->input('language');
    //     $documentId = $request->input('document');

    //     $document = Documents::where('id', $documentId)->first();
            
    //     $retreiveDocumentPath = new CommonFunctionsController();
    //     $file_path_full = $retreiveDocumentPath->retreive_document_path($document);
            
    //     $documentContext = '';

    //     $getFileTextContent = new CommonFunctionsController();
    //     $documentContext = $getFileTextContent->sendToNodeGetFullText($file_path_full);
    //     $context= $documentContext['content'];


    //     if (empty($language)) {
    //         return response()->json(['response' => 'Please select language to continue translation.']);
    //     }

    //     try {
    //         $translate = new TranslateClient([
    //             'key' => env('GOOGLE_TRANSLATE_API_KEY'),
    //         ]);

    //         $translation = $translate->translate($context, [
    //             'target' => $language,
    //         ]);

    //         return response()->json(['response' => $translation['text']]);
    //     } catch (\Exception $e) {
    //         \Log::error('Translation failed: ' . $e->getMessage());
    //         return response()->json(['response' => 'Something went wrong.'], 500);
    //     }
    // }

    public function translate_document(Request $request)
{
    $language = $request->input('language');
    $documentId = $request->input('document');

    if (empty($language)) {
        return response()->json(['response' => 'Please select language to continue translation.']);
    }

    $document = Documents::find($documentId);
    if (!$document) {
        return response()->json(['response' => 'Document not found.'], 404);
    }

    // $fileController = new CommonFunctionsController();
    // $file_path_full = $fileController->retreive_document_path($document);

    // $documentContext = $fileController->sendToNodeGetFullText($file_path_full);
    // $context = $documentContext['content'] ?? '';

    $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
    $index = $client->index('python_test_docs_3');
    $searchResult = $index->search('', [
        'filter' => "sql_document = '{$document->id}'",
    ])->getHits();
    $documentPages = $searchResult ?? [];

    $documentPages = collect($documentPages)->sortBy('page')->values();

    $context = $documentPages->pluck('content')
    ->map(function($content) {
        return str_replace("\u000b", "\n", $content);
    })
    ->join("\n\n");

    if (empty($context)) {
        return response()->json(['response' => 'Document is empty or could not be read.'], 400);
    }

    try {
        $translate = new \Google\Cloud\Translate\V2\TranslateClient([
            'key' => env('GOOGLE_TRANSLATE_API_KEY'),
        ]);

        $segments = preg_split(
            '/(<h[34]>.*?<\/h[34]>|<li>.*?<\/li>|<p>.*?<\/p>|<br\s*\/?>|\n{2,})/i',
            $context,
            -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
        );

        $translatedSegments = [];

        foreach ($segments as $segment) {
            $trimmed = trim(strip_tags($segment));

            if ($trimmed !== '') {
                $translated = $translate->translate($trimmed, ['target' => $language]);

                if (preg_match('/^<([a-z0-9]+)[^>]*>.*<\/\1>$/i', $segment, $matches)) {
                    $tag = $matches[1];
                    $translatedSegments[] = "<$tag>{$translated['text']}</$tag>";
                } else {
                    $translatedSegments[] = $translated['text'];
                }
            } else {
                $translatedSegments[] = $segment;
            }
        }

        $finalTranslatedHtml = implode('', $translatedSegments);

        return response()->json(['response' => $finalTranslatedHtml]);
    } catch (\Exception $e) {
        \Log::error('Translation failed: ' . $e->getMessage());
        return response()->json(['response' => 'Something went wrong during translation.'], 500);
    }
}



public function get_tone($id,Request $request)
    {
        try {
            $document = Documents::where('id', $id)->first();
            
            // $retreiveDocumentPath = new CommonFunctionsController();
            // $file_path_full = $retreiveDocumentPath->retreive_document_path($document);
                
            // $documentContext = '';

            //     $getFileTextContent = new CommonFunctionsController();
            //     $documentContext = $getFileTextContent->sendToNodeGetFullText($file_path_full);
            //     $context= $documentContext['content'];

                $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
                $index = $client->index('python_test_docs_3');
                $searchResult = $index->search('', [
                    'filter' => "sql_document = '{$document->id}'",
                ])->getHits();
                $documentPages = $searchResult ?? [];
        
                $documentPages = collect($documentPages)->sortBy('page')->values();

                $context = $documentPages->pluck('content')
                ->map(function($content) {
                    return str_replace("\u000b", "\n", $content);
                })
                ->join("\n\n");


                $wordCount = str_word_count(strip_tags($context));
                if ($wordCount > 12000) {
                    return response()->json([
                        'status' => 'fail',
                        'message' => 'The document exceeds the word limit for tone analysis.',
                        'word_count' => $wordCount,
                    ], 400);
                }
                $completion = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini', 
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' =>"
                            
                            You are a strict assistant specialized in tone analysis.

                            Instructions:
                            1. Only analyze and describe the tone of the following document.
                            2. Do not rewrite the document.
                            3. Format your response clearly using:
                            - <h3> for section headings
                            - <ul> and <li> for bullet points
                            - No <head> or <body> tags
                            4. End your response with this sentence: Please select a tone if you'd like me to rewrite the document.

                            Context:
                            $context
                            ",
                        ],
                        [
                            'role' => 'user',
                            'content' => 'What is the tone of the document above?',
                        ],
                    ]
                ]);
        
                return response()->json([
                    'status' => 'success',
                    'response' => $completion->choices[0]->message->content,
                    'wordCount' => $wordCount,
                ]);

        } catch (\Exception $e) {
    
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
    public function covert_document_tone(Request $request)
    {
        try {

            $tone = $request->input('tone');
            $documentId = $request->input('document');

            $document = Documents::where('id', $documentId)->first();
            
            // $retreiveDocumentPath = new CommonFunctionsController();
            // $file_path_full = $retreiveDocumentPath->retreive_document_path($document);
                
            // $documentContext = '';

                // $getFileTextContent = new CommonFunctionsController();
                // $documentContext = $getFileTextContent->sendToNodeGetFullText($file_path_full);
                // $context= $documentContext['content'];

                $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
                $index = $client->index('python_test_docs_3');

                $searchResult = $index->search('', [
                    'filter' => "sql_document = '{$document->id}'",
                ])->getHits();
                $documentPages = $searchResult ?? [];
        
                $documentPages = collect($documentPages)->sortBy('page')->values();

                $context = $documentPages->pluck('content')
                ->map(function($content) {
                    return str_replace("\u000b", "\n", $content);
                })
                ->join("\n\n");

                $wordCount = str_word_count(strip_tags($context));
                if ($wordCount > 12000) {
                    return response()->json([
                        'status' => 'fail',
                        'message' => 'The document exceeds the word limit for tone analysis.',
                        'word_count' => $wordCount,
                        'context' => $context,
                    ], 400);
                }
                $completion = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini', 
                    'temperature' => 0.4,
                    'max_tokens' => 12000,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' =>"You are a strict assistant specialized in tone analysis and tone modification.

                            Instructions:
                            1. Rewrite the provided document in the tone specified by the user.
                            2. Do not describe the original tone.
                            3. Format your response clearly using:
                            - <h3> for section headings
                            - <ul> and <li> for bullet points
                            - No <head> or <body> tags
                            4. Ensure the rewritten content fits entirely within a 12,000 token window.
                            5. If you cannot convert the tone, give the exact reason why. Do not give general reasons.

                            Context:
                            $context
                            Please select a tone if you'd like me to rewrite the document.",
                        ],
                        [
                            'role' => 'user',
                            'content' => "Rewrite the document in a $tone tone.",
                        ],
                    ]
                ]);

        
                return response()->json([
                    'status' => 'success',
                    'response' => $completion->choices[0]->message->content,
                    'wordCount' => $wordCount,
                    'context' => $context,
                ]);

        } catch (\Exception $e) {
    
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage(),
                'wordCount' => $wordCount,
                'context' => $context,
            ], 500);
        }    
    }


    public function generate_document_content(Request $request)
    {
        try {

            $message = $request->input('message');
            $documentId = $request->input('document');

            $document = Documents::where('id', $documentId)->first();
            
            // $retreiveDocumentPath = new CommonFunctionsController();
            // $file_path_full = $retreiveDocumentPath->retreive_document_path($document);
                
            // $documentContext = '';

                // $getFileTextContent = new CommonFunctionsController();
                // $documentContext = $getFileTextContent->sendToNodeGetFullText($file_path_full);
                // $context= $documentContext['content'];

                $client = new Client(env('MEILISEARCH_HOST'), env('MEILISEARCH_KEY'));
                $index = $client->index('python_test_docs_3');

                $searchResult = $index->search('', [
                    'filter' => "sql_document = '{$document->id}'",
                ])->getHits();
                $documentPages = $searchResult ?? [];
        
                $documentPages = collect($documentPages)->sortBy('page')->values();

                $context = $documentPages->pluck('content')
                ->map(function($content) {
                    return str_replace("\u000b", "\n", $content);
                })
                ->join("\n\n");

                $completion = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini', 
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' =>"You are a content generation assistant.

                            Your ONLY job is to generate **new content** based on the user’s request and the context provided.
                            Do NOT summarize, answer questions, explain, or analyze anything.
                            Strictly focus on creating new material as directed by the user.

                            IMPORTANT FORMATTING RULES:
                            - Use <h3> for major section titles and <h4> for subsections.
                            - Use <ul><li>...</li></ul> for any lists (bulleted).
                            - Use <ol><li>...</li></ol> for any lists (numbered).
                            - Do NOT include <html>, <head>, <body>, or any boilerplate tags.
                            - Do NOT use markdown (e.g., **, #, ##). Only use valid and semantic HTML elements.
                            - Keep the structure clean, semantic, and well-formatted HTML.
                            - Use <br> tags to separate lines when appropriate (e.g., in greetings, closings, or multiline blocks like names, job titles, contact info).
                            - Always format closings like this:
                                Thank you for your assistance.<br>Best regards,<br>[Your Name]<br>[Your Job Title]<br>[Your Contact Information]<br>[Your Employee ID (if applicable)]
                            - You may engage in friendly greetings or short acknowledgments.

                            Use the following context as the foundation:

                            Context:
                            $context
                            Please select a tone if you'd like me to rewrite the document.",
                        ],
                        [
                            'role' => 'user',
                            'content' => $message,
                        ],
                    ]
                ]);
        
                return response()->json([
                    'status' => 'success',
                    'response' => $completion->choices[0]->message->content,
                ]);

        } catch (\Exception $e) {
    
            return response()->json([
                'status' => "fail",
                'message' => 'Request failed',
                'error' => $e->getMessage()
            ], 500);
        }    
    }
}
//1744879766413982