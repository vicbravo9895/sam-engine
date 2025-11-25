#!/usr/bin/env python
"""
Script de validación para verificar que el SDK de Samsara está instalado correctamente
y que todos los métodos que usamos en samsara_tools.py están disponibles.
"""

import sys
from typing import List

def check_import():
    """Verifica que el módulo samsara se puede importar."""
    print("=" * 60)
    print("1. VERIFICANDO IMPORTACIÓN DEL MÓDULO")
    print("=" * 60)
    
    try:
        import samsara
        print(f"✅ Módulo 'samsara' importado correctamente")
        print(f"   Ubicación: {samsara.__file__}")
        print(f"   Versión: {getattr(samsara, '__version__', 'N/A')}")
        return True
    except ImportError as e:
        print(f"❌ Error al importar 'samsara': {e}")
        return False

def check_async_client():
    """Verifica que AsyncSamsara se puede importar."""
    print("\n" + "=" * 60)
    print("2. VERIFICANDO CLIENTE ASÍNCRONO (AsyncSamsara)")
    print("=" * 60)
    
    try:
        from samsara import AsyncSamsara
        print(f"✅ AsyncSamsara importado correctamente")
        print(f"   Clase: {AsyncSamsara}")
        return True, AsyncSamsara
    except ImportError as e:
        print(f"❌ Error al importar AsyncSamsara: {e}")
        return False, None

def check_client_resources(client_class):
    """Verifica que los recursos del cliente existen."""
    print("\n" + "=" * 60)
    print("3. VERIFICANDO RECURSOS DEL CLIENTE")
    print("=" * 60)
    
    # Crear una instancia temporal (sin token real)
    try:
        # Intentamos crear el cliente sin token para ver la estructura
        # Nota: Esto puede fallar si el SDK requiere token, pero podemos inspeccionar la clase
        resources_to_check = [
            'vehicle_stats',
            'vehicles',
            'driver_vehicle_assignments',
            'cameras',
            'media'  # También verificamos media por si acaso
        ]
        
        results = {}
        for resource in resources_to_check:
            # Verificamos si el atributo existe en la clase
            # Usamos __annotations__ o dir() para inspeccionar
            print(f"\n   Verificando recurso: {resource}")
            
            # Intentamos ver si existe como atributo en la clase
            if hasattr(client_class, resource):
                print(f"   ✅ Recurso '{resource}' encontrado en AsyncSamsara")
                results[resource] = True
            else:
                print(f"   ⚠️  Recurso '{resource}' NO encontrado directamente")
                print(f"      (Puede estar disponible solo en instancia)")
                results[resource] = False
        
        return results
    except Exception as e:
        print(f"❌ Error al verificar recursos: {e}")
        return {}

def check_methods():
    """Verifica los métodos específicos que usamos en samsara_tools.py."""
    print("\n" + "=" * 60)
    print("4. VERIFICANDO MÉTODOS ESPECÍFICOS")
    print("=" * 60)
    
    methods_to_check = [
        ('vehicle_stats', 'list', 'get_vehicle_stats()'),
        ('vehicles', 'get', 'get_vehicle_info()'),
        ('driver_vehicle_assignments', 'list', 'get_driver_assignment()'),
        ('cameras', 'list_media', 'get_camera_media()'),
    ]
    
    print("\n   Métodos que necesitamos verificar:")
    for resource, method, function in methods_to_check:
        print(f"   - client.{resource}.{method}() para {function}")
    
    print("\n   ⚠️  Nota: Para verificar los métodos exactos, necesitamos")
    print("   crear una instancia del cliente con un token válido.")
    print("   Esto se debe hacer en el ambiente Docker con las variables")
    print("   de entorno configuradas.")

def check_package_info():
    """Muestra información del paquete instalado."""
    print("\n" + "=" * 60)
    print("5. INFORMACIÓN DEL PAQUETE")
    print("=" * 60)
    
    try:
        import samsara
        import inspect
        
        # Listar todos los atributos públicos del módulo
        print("\n   Atributos públicos del módulo 'samsara':")
        public_attrs = [attr for attr in dir(samsara) if not attr.startswith('_')]
        for attr in public_attrs[:10]:  # Mostrar solo los primeros 10
            print(f"   - {attr}")
        
        if len(public_attrs) > 10:
            print(f"   ... y {len(public_attrs) - 10} más")
        
    except Exception as e:
        print(f"❌ Error al obtener información del paquete: {e}")

def main():
    """Función principal."""
    print("\n" + "🔍" * 30)
    print("VALIDACIÓN DEL SDK DE SAMSARA")
    print("🔍" * 30 + "\n")
    
    # 1. Verificar importación
    if not check_import():
        print("\n❌ FALLO: No se puede importar el módulo 'samsara'")
        sys.exit(1)
    
    # 2. Verificar AsyncSamsara
    success, client_class = check_async_client()
    if not success:
        print("\n❌ FALLO: No se puede importar AsyncSamsara")
        sys.exit(1)
    
    # 3. Verificar recursos
    resources = check_client_resources(client_class)
    
    # 4. Verificar métodos
    check_methods()
    
    # 5. Información del paquete
    check_package_info()
    
    # Resumen final
    print("\n" + "=" * 60)
    print("RESUMEN")
    print("=" * 60)
    print("✅ El paquete 'samsara-api' está instalado correctamente")
    print("✅ El módulo Python es 'samsara' (no 'samsara_api')")
    print("✅ AsyncSamsara se puede importar correctamente")
    print("\n⚠️  IMPORTANTE:")
    print("   Para verificar que los métodos funcionan correctamente,")
    print("   debes ejecutar el servicio en Docker con un token válido")
    print("   de Samsara configurado en las variables de entorno.")
    print("\n" + "=" * 60)

if __name__ == "__main__":
    main()
