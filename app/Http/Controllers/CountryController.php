<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\Country;

/**
 * @OA\Tag(
 *     name="Country",
 *     description="Country related APIs"
 * )
 */
class CountryController extends Controller
{
    /**
     * Get Countries List
     *
     * @OA\Get(
     *     path="/api/countries",
     *     tags={"Country"},
     *     summary="Get list of countries",
     *     description="Returns list of countries with flag, currency and country code",
     *     @OA\Response(
     *         response=200,
     *         description="Countries List",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Countries List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="India"),
     *                     @OA\Property(property="code", type="string", example="IN"),
     *                     @OA\Property(property="flag", type="string", example="https://flagcdn.com/w20/in.png"),
     *                     @OA\Property(
     *                         property="currency",
     *                         type="object",
     *                         @OA\Property(property="currency", type="string", example="INR")
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function countries()
    {
        $countries = Country::select(
            'id',
            'country_name',
            'country_code',
            'currency'
        )->get()->map(function ($country) {

            $code = strtolower($country->country_code);

            return [
                'id'       => $country->id,
                'name'     => $country->country_name,
                'code'     => strtoupper($country->country_code),
                'flag'     => "https://flagcdn.com/w20/{$code}.png",
                'currency' => [
                    'currency' => $country->currency,
                ],
            ];
        });

        return response()->json([
            'status'  => 200,
            'data'    => $countries,
            'message' => 'Countries List',
        ]);
    }
}
