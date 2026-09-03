<?php

namespace Plugins\AiMember\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Plugin/ai-member/Index', [
            'pluginSlug' => 'ai-member',
            'pluginPage' => 'Index',
            'plugin_ui_page' => [
                'plugin_slug' => 'ai-member',
                'page' => 'Index',
                'ui_export' => 'SettingsPage',
                'ui_mode' => 'runtime',
            ],
        ]);
    }
}
