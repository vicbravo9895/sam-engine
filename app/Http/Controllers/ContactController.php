<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Display a listing of the contacts.
     */
    public function index(Request $request): Response
    {
        $query = Contact::query();

        // Filtros
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('role', 'like', "%{$search}%");
            });
        }

        if ($request->has('entity_type') && $request->entity_type) {
            if ($request->entity_type === 'global') {
                $query->whereNull('entity_type');
            } else {
                $query->where('entity_type', $request->entity_type);
            }
        }

        $contacts = $query->orderByPriority()->paginate(20);

        return Inertia::render('contacts/index', [
            'contacts' => $contacts,
            'filters' => $request->only(['type', 'search', 'entity_type']),
            'types' => Contact::getTypes(),
        ]);
    }

    /**
     * Show the form for creating a new contact.
     */
    public function create(): Response
    {
        return Inertia::render('contacts/create', [
            'types' => Contact::getTypes(),
        ]);
    }

    /**
     * Store a newly created contact in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'nullable|string|max:255',
            'type' => 'required|in:operator,monitoring_team,supervisor,emergency,dispatch',
            'phone' => 'nullable|string|max:20',
            'phone_whatsapp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'entity_type' => 'nullable|in:vehicle,driver',
            'entity_id' => 'nullable|string|max:255',
            'is_default' => 'boolean',
            'priority' => 'integer|min:0|max:100',
            'is_active' => 'boolean',
            'notification_preferences' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        // Si se marca como default, quitar default de otros del mismo tipo
        if ($validated['is_default'] ?? false) {
            $this->clearDefaultForType($validated['type'], $validated['entity_type'] ?? null, $validated['entity_id'] ?? null);
        }

        $contact = Contact::create($validated);

        return redirect()->route('contacts.index')
            ->with('success', 'Contacto creado exitosamente.');
    }

    /**
     * Display the specified contact.
     */
    public function show(Contact $contact): Response
    {
        return Inertia::render('contacts/show', [
            'contact' => $contact,
            'types' => Contact::getTypes(),
        ]);
    }

    /**
     * Show the form for editing the specified contact.
     */
    public function edit(Contact $contact): Response
    {
        return Inertia::render('contacts/edit', [
            'contact' => $contact,
            'types' => Contact::getTypes(),
        ]);
    }

    /**
     * Update the specified contact in storage.
     */
    public function update(Request $request, Contact $contact)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'nullable|string|max:255',
            'type' => 'required|in:operator,monitoring_team,supervisor,emergency,dispatch',
            'phone' => 'nullable|string|max:20',
            'phone_whatsapp' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'entity_type' => 'nullable|in:vehicle,driver',
            'entity_id' => 'nullable|string|max:255',
            'is_default' => 'boolean',
            'priority' => 'integer|min:0|max:100',
            'is_active' => 'boolean',
            'notification_preferences' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        // Si se marca como default, quitar default de otros del mismo tipo
        if (($validated['is_default'] ?? false) && !$contact->is_default) {
            $this->clearDefaultForType($validated['type'], $validated['entity_type'] ?? null, $validated['entity_id'] ?? null, $contact->id);
        }

        $contact->update($validated);

        return redirect()->route('contacts.index')
            ->with('success', 'Contacto actualizado exitosamente.');
    }

    /**
     * Remove the specified contact from storage.
     */
    public function destroy(Contact $contact)
    {
        $contact->delete();

        return redirect()->route('contacts.index')
            ->with('success', 'Contacto eliminado exitosamente.');
    }

    /**
     * Toggle the active status of a contact.
     */
    public function toggleActive(Contact $contact)
    {
        $contact->update(['is_active' => !$contact->is_active]);

        return back()->with('success', 
            $contact->is_active ? 'Contacto activado.' : 'Contacto desactivado.'
        );
    }

    /**
     * Set a contact as the default for its type.
     */
    public function setDefault(Contact $contact)
    {
        $this->clearDefaultForType($contact->type, $contact->entity_type, $contact->entity_id, $contact->id);
        $contact->update(['is_default' => true]);

        return back()->with('success', 'Contacto establecido como predeterminado.');
    }

    /**
     * Clear the default flag for all contacts of a type.
     */
    private function clearDefaultForType(string $type, ?string $entityType, ?string $entityId, ?int $excludeId = null): void
    {
        $query = Contact::where('type', $type)->where('is_default', true);

        if ($entityType) {
            $query->where('entity_type', $entityType)->where('entity_id', $entityId);
        } else {
            $query->whereNull('entity_type');
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $query->update(['is_default' => false]);
    }
}

