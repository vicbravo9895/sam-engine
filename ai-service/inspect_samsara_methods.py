#!/usr/bin/env python
"""
Script para inspeccionar los métodos disponibles en cada recurso del SDK de Samsara.
"""

from samsara import AsyncSamsara
import inspect

# Crear cliente con token dummy para inspeccionar estructura
client = AsyncSamsara(token="dummy_token_for_inspection")

print("=" * 70)
print("MÉTODOS DISPONIBLES EN RECURSOS DEL SDK DE SAMSARA")
print("=" * 70)

resources = {
    'vehicle_stats': client.vehicle_stats,
    'vehicles': client.vehicles,
    'driver_vehicle_assignments': client.driver_vehicle_assignments,
    'media': client.media
}

for resource_name, resource_obj in resources.items():
    print(f"\n📦 {resource_name}")
    print(f"   Tipo: {type(resource_obj).__name__}")
    
    # Obtener todos los métodos públicos
    methods = [m for m in dir(resource_obj) if not m.startswith('_') and callable(getattr(resource_obj, m))]
    
    print(f"   Métodos disponibles ({len(methods)}):")
    for method in sorted(methods):
        method_obj = getattr(resource_obj, method)
        # Intentar obtener la firma
        try:
            sig = inspect.signature(method_obj)
            print(f"      - {method}{sig}")
        except:
            print(f"      - {method}(...)")

print("\n" + "=" * 70)
