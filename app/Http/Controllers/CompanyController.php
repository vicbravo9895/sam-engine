<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\BehaviorLabelTranslator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CompanyController extends Controller
{
    /**
     * Show the company settings page.
     */
    public function edit(Request $request)
    {
        $user = $request->user();

        // Only admins can edit company settings
        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden editar la configuración de la empresa.');
        }

        $company = Company::findOrFail($user->company_id);

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        return Inertia::render('company/edit', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'email' => $company->email,
                'phone' => $company->phone,
                'address' => $company->address,
                'city' => $company->city,
                'state' => $company->state,
                'country' => $company->country,
                'timezone' => $company->timezone ?? 'America/Mexico_City',
                'postal_code' => $company->postal_code,
                'logo_url' => $company->logo_url,
                'is_active' => $company->is_active,
                'has_samsara_key' => $company->hasSamsaraApiKey(),
                'created_at' => $company->created_at,
            ],
            'timezones' => $this->getAvailableTimezones(),
        ]);
    }

    /**
     * Update the company settings.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden editar la configuración de la empresa.');
        }

        $company = $user->company;

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:2'],
            'timezone' => ['nullable', 'string', 'max:50', 'timezone:all'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'image', 'max:2048'], // 2MB max
        ], [
            'name.required' => 'El nombre de la empresa es requerido.',
            'email.email' => 'El correo electrónico no es válido.',
            'timezone.timezone' => 'La zona horaria no es válida.',
            'logo.image' => 'El logo debe ser una imagen.',
            'logo.max' => 'El logo no puede superar 2MB.',
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Delete old logo
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }

            $path = $request->file('logo')->store('company-logos', 'public');
            $validated['logo_path'] = $path;
        }

        // Update slug if name changed
        if ($validated['name'] !== $company->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        unset($validated['logo']);
        $company->update($validated);

        return back()->with('success', 'Configuración de empresa actualizada exitosamente.');
    }

    /**
     * Update the Samsara API key.
     */
    public function updateSamsaraKey(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden actualizar la API key de Samsara.');
        }

        $company = $user->company;

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        $validated = $request->validate([
            'samsara_api_key' => ['required', 'string', 'min:20'],
        ], [
            'samsara_api_key.required' => 'La API key de Samsara es requerida.',
            'samsara_api_key.min' => 'La API key debe tener al menos 20 caracteres.',
        ]);

        $company->update([
            'samsara_api_key' => $validated['samsara_api_key'],
        ]);

        return redirect()->route('company.edit')->with('success', 'API key de Samsara actualizada exitosamente.');
    }

    /**
     * Remove the Samsara API key.
     */
    public function removeSamsaraKey(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden eliminar la API key de Samsara.');
        }

        $company = $user->company;

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        $company->update([
            'samsara_api_key' => null,
        ]);

        return redirect()->route('company.edit')->with('success', 'API key de Samsara eliminada.');
    }

    /**
     * Show the AI settings configuration page.
     */
    public function editAiSettings(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden editar la configuración de AI.');
        }

        $company = Company::findOrFail($user->company_id);

        $aiConfig = $company->getAiConfig();

        // Replace safety_stream_notify with rules-based format (migrates legacy labels)
        $aiConfig['safety_stream_notify'] = $company->getSafetyStreamNotifyConfig();

        return Inertia::render('company/ai-settings', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
            ],
            'aiConfig' => $aiConfig,
            'notificationConfig' => $company->getNotificationConfig(),
            'defaults' => [
                'ai_config' => Company::DEFAULT_AI_CONFIG,
                'notifications' => Company::DEFAULT_NOTIFICATION_CONFIG,
            ],
            'canonicalBehaviorLabels' => config('safety_signals.canonical_labels', []),
            'labelTranslations' => collect(config('safety_signals.canonical_labels', []))
                ->mapWithKeys(fn (string $label) => [$label => BehaviorLabelTranslator::getName($label)])
                ->all(),
        ]);
    }

    /**
     * Update the AI configuration settings.
     */
    public function updateAiSettings(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden actualizar la configuración de AI.');
        }

        $company = $user->company;

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        $validated = $request->validate([
            // Investigation windows
            'investigation_windows' => ['required', 'array'],
            'investigation_windows.correlation_window_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'investigation_windows.media_window_seconds' => ['required', 'integer', 'min:30', 'max:600'],
            'investigation_windows.safety_events_before_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'investigation_windows.safety_events_after_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'investigation_windows.vehicle_stats_before_minutes' => ['required', 'integer', 'min:1', 'max:30'],
            'investigation_windows.vehicle_stats_after_minutes' => ['required', 'integer', 'min:1', 'max:15'],
            'investigation_windows.camera_media_window_minutes' => ['required', 'integer', 'min:1', 'max:10'],
            // Monitoring
            'monitoring' => ['required', 'array'],
            'monitoring.confidence_threshold' => ['required', 'numeric', 'min:0.5', 'max:0.99'],
            'monitoring.max_revalidations' => ['required', 'integer', 'min:1', 'max:20'],
            'monitoring.check_intervals' => ['required', 'array', 'min:1'],
            'monitoring.check_intervals.*' => ['integer', 'min:1', 'max:120'],
            // Notification channels
            'channels_enabled' => ['required', 'array'],
            'channels_enabled.sms' => ['required', 'boolean'],
            'channels_enabled.whatsapp' => ['required', 'boolean'],
            'channels_enabled.call' => ['required', 'boolean'],
            'channels_enabled.email' => ['required', 'boolean'],
            // Safety stream notify
            'safety_stream_notify' => ['sometimes', 'array'],
            'safety_stream_notify.enabled' => ['required_with:safety_stream_notify', 'boolean'],
            'safety_stream_notify.rules' => ['required_with:safety_stream_notify', 'array'],
            'safety_stream_notify.rules.*.id' => ['required', 'string'],
            'safety_stream_notify.rules.*.conditions' => ['required', 'array', 'min:1'],
            'safety_stream_notify.rules.*.conditions.*' => ['required', 'string'],
            'safety_stream_notify.rules.*.action' => ['required', 'string', 'in:notify'],
        ], [
            'investigation_windows.required' => 'Los parámetros de investigación son requeridos.',
            'monitoring.required' => 'Los parámetros de monitoreo son requeridos.',
            'monitoring.check_intervals.required' => 'Debes especificar al menos un intervalo de revalidación.',
            'monitoring.check_intervals.min' => 'Debes especificar al menos un intervalo de revalidación.',
            'channels_enabled.required' => 'Los canales de notificación son requeridos.',
        ]);

        // Build ai_config from validated data
        $aiConfig = [
            'investigation_windows' => $validated['investigation_windows'],
            'monitoring' => $validated['monitoring'],
        ];

        // Include safety_stream_notify if provided
        if (isset($validated['safety_stream_notify'])) {
            $aiConfig['safety_stream_notify'] = [
                'enabled' => $validated['safety_stream_notify']['enabled'],
                'rules' => $validated['safety_stream_notify']['rules'],
            ];
        }

        // Build notifications config
        $notificationsConfig = [
            'channels_enabled' => $validated['channels_enabled'],
        ];

        // Update settings
        $settings = $company->settings ?? [];
        
        // Merge with existing config to preserve escalation_matrix and other settings
        $settings['ai_config'] = array_replace_recursive(
            $settings['ai_config'] ?? Company::DEFAULT_AI_CONFIG,
            $aiConfig
        );

        // safety_stream_notify.rules is a list, not a map — replace it entirely
        if (isset($aiConfig['safety_stream_notify'])) {
            $settings['ai_config']['safety_stream_notify'] = $aiConfig['safety_stream_notify'];
        }
        
        $settings['notifications'] = array_replace_recursive(
            $settings['notifications'] ?? Company::DEFAULT_NOTIFICATION_CONFIG,
            $notificationsConfig
        );
        
        $company->settings = $settings;
        $company->save();

        return back()->with('success', 'Configuración de AI actualizada exitosamente.');
    }

    /**
     * Reset AI settings to defaults.
     */
    public function resetAiSettings(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            abort(403, 'Solo los administradores pueden restablecer la configuración de AI.');
        }

        $company = $user->company;

        if (!$company) {
            abort(404, 'No se encontró la empresa.');
        }

        $settings = $company->settings ?? [];
        
        // Reset to defaults
        $settings['ai_config'] = Company::DEFAULT_AI_CONFIG;
        $settings['notifications'] = Company::DEFAULT_NOTIFICATION_CONFIG;
        
        $company->settings = $settings;
        $company->save();

        return back()->with('success', 'Configuración de AI restablecida a valores predeterminados.');
    }

    /**
     * Get available timezones for Mexico and common American timezones.
     */
    private function getAvailableTimezones(): array
    {
        return [
            // México
            'America/Mexico_City' => 'Ciudad de México (UTC-6)',
            'America/Cancun' => 'Cancún (UTC-5)',
            'America/Merida' => 'Mérida (UTC-6)',
            'America/Monterrey' => 'Monterrey (UTC-6)',
            'America/Matamoros' => 'Matamoros (UTC-6)',
            'America/Mazatlan' => 'Mazatlán (UTC-7)',
            'America/Chihuahua' => 'Chihuahua (UTC-7)',
            'America/Hermosillo' => 'Hermosillo (UTC-7)',
            'America/Tijuana' => 'Tijuana (UTC-8)',
            // Estados Unidos
            'America/New_York' => 'Nueva York (UTC-5)',
            'America/Chicago' => 'Chicago (UTC-6)',
            'America/Denver' => 'Denver (UTC-7)',
            'America/Los_Angeles' => 'Los Ángeles (UTC-8)',
            'America/Phoenix' => 'Phoenix (UTC-7)',
            'America/Houston' => 'Houston (UTC-6)',
            // Centroamérica
            'America/Guatemala' => 'Guatemala (UTC-6)',
            'America/El_Salvador' => 'El Salvador (UTC-6)',
            'America/Costa_Rica' => 'Costa Rica (UTC-6)',
            'America/Panama' => 'Panamá (UTC-5)',
            // Sudamérica
            'America/Bogota' => 'Bogotá (UTC-5)',
            'America/Lima' => 'Lima (UTC-5)',
            'America/Santiago' => 'Santiago (UTC-4)',
            'America/Sao_Paulo' => 'São Paulo (UTC-3)',
            'America/Buenos_Aires' => 'Buenos Aires (UTC-3)',
            // Europa
            'Europe/Madrid' => 'Madrid (UTC+1)',
            'Europe/London' => 'Londres (UTC+0)',
            // UTC
            'UTC' => 'UTC (UTC+0)',
        ];
    }
}

