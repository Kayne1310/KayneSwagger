<?php

namespace Kayne\Swagger\Http\Controllers;

use Illuminate\Routing\Controller;
use Kayne\Swagger\SwaggerGenerator;

class SwaggerController extends Controller
{
    /**
     * Hiển thị Swagger UI
     */
    public function ui()
    {
        return view('swagger::ui');
    }

    /**
     * API spec JSON
     */
    public function spec()
    {
        $generator = new SwaggerGenerator();
        $spec = $generator->generate();

        return response()->json($spec);
    }
}
