<?php

namespace App\Http\Controllers;
/**
 * @OA\Info(
 *     title="ORVELL PULSE Wholesale ERP Backend API",
 *     version="1.0.0",
 *     description="Production REST API documentation for ORVELL PULSE Wholesale ERP system with Spatie RBAC, multi-company isolation, inventory tracking, cashier settlement, and automated reporting."
 * )
 *
 * @OA\Server(
 *     url="/",
 *     description="Default API Host"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Enter Sanctum personal access token as: Bearer <token>"
 * )
 */


abstract class Controller
{
    
}
