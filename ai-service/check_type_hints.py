#!/usr/bin/env python
"""
Script simple para validar los type hints de las funciones en samsara_tools.py
sin importar todo el módulo (evitando imports circulares).
"""

import ast
import sys
from pathlib import Path

def check_type_hints(file_path: str):
    """Analiza el archivo y verifica los type hints."""
    print("=" * 60)
    print("VALIDACIÓN DE TYPE HINTS EN SAMSARA_TOOLS.PY")
    print("=" * 60)
    
    with open(file_path, 'r') as f:
        tree = ast.parse(f.read())
    
    functions = []
    for node in ast.walk(tree):
        if isinstance(node, ast.AsyncFunctionDef) and node.name.startswith('get_'):
            functions.append(node)
    
    print(f"\nFunciones encontradas: {len(functions)}\n")
    
    issues = []
    
    for func in functions:
        print(f"📝 {func.name}:")
        
        for arg in func.args.args:
            arg_name = arg.arg
            annotation = ast.unparse(arg.annotation) if arg.annotation else "No annotation"
            
            # Buscar el default value
            defaults_offset = len(func.args.args) - len(func.args.defaults)
            arg_index = func.args.args.index(arg)
            default_index = arg_index - defaults_offset
            
            if default_index >= 0 and default_index < len(func.args.defaults):
                default = ast.unparse(func.args.defaults[default_index])
            else:
                default = "No default"
            
            print(f"   - {arg_name}: {annotation} = {default}")
            
            # Verificar si hay problemas
            if default == "None" and annotation != "No annotation":
                if "Optional" not in annotation and "None" not in annotation:
                    issue = f"❌ {func.name}.{arg_name}: Type '{annotation}' con default None debe usar Optional"
                    issues.append(issue)
                    print(f"      {issue}")
                else:
                    print(f"      ✅ Correcto: usa Optional o Union con None")
        
        # Return type
        return_annotation = ast.unparse(func.returns) if func.returns else "No return annotation"
        print(f"   Returns: {return_annotation}\n")
    
    print("=" * 60)
    if issues:
        print(f"❌ PROBLEMAS ENCONTRADOS: {len(issues)}")
        for issue in issues:
            print(f"   {issue}")
        return False
    else:
        print("✅ TODOS LOS TYPE HINTS SON CORRECTOS")
        return True

if __name__ == "__main__":
    file_path = Path(__file__).parent / "tools" / "samsara_tools.py"
    success = check_type_hints(str(file_path))
    sys.exit(0 if success else 1)
