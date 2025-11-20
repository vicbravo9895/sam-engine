import requests

def get_vehicle_stats(vehicle_id: str) -> dict:
    base_url = "https://api.samsara.com"

    url = f"{base_url}/fleet/vehicles/stats/feed"

    api_token = ""

    headers = {
        "Authorization": "Bearer " + api_token,
        "Content-Type": "application/json"
    }

    params = {
        "vehicleIds": vehicle_id,
        "types": ["gps", "engineStates"]
    }

    try:
        response = requests.get(url, headers=headers, params=params)
        response.raise_for_status()  # Lanza un error si el código de estado no es 200

        data = response.json()

        # Procesar datos relevantes según el esquema
        if "data" in data and len(data["data"]) > 0:
            vehicle_data = data["data"][0]

            gps_data = vehicle_data.get("gps", [])
            engine_states = vehicle_data.get("engineStates", [])

            return {
                "gps": [
                    {
                        "time": gps.get("time"),
                        "latitude": gps.get("latitude"),
                        "longitude": gps.get("longitude"),
                        "formatted_location": gps.get("reverseGeo", {}).get("formattedLocation"),
                        "address_name": gps.get("address", {}).get("name")
                    }
                    for gps in gps_data
                ],
                "engine_states": [
                    {
                        "time": state.get("time"),
                        "value": state.get("value")
                    }
                    for state in engine_states
                ]
            }

        return {}

    except requests.exceptions.RequestException as e:
        # Manejar errores de la solicitud
        print(f"Error al obtener estadísticas del vehículo: {e}")
        return {}