<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\Country;

class CountryController extends Controller
{
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
