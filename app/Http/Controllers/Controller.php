<?php

namespace App\Http\Controllers;
/**
 * @OA\Info(
 *     title="Orvell API",
 *     version="1.0.0",
 *     description="API documentation for Orvell Website"
 * )
 *
 * @OA\Server(
 *     url="/projects/orvell/public",
 *     description="API Base URL"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */


abstract class Controller
{
    
}
