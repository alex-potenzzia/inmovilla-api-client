<?php
// Permitir solicitudes de cualquier origen (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Responder automáticamente a las solicitudes OPTIONS (necesario para CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Cargar el autoloader de Composer
require __DIR__ . '/../vendor/autoload.php';

// Importar las clases necesarias de la biblioteca Inmovilla
use Inmovilla\ApiClient\ApiClientConfig;
use Inmovilla\ApiClient\ApiClientFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Inmovilla\Repository\PropiedadRepository;
use Inmovilla\Repository\PropiedadFichaRepository;

// Función para manejar errores
function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(["error" => $message]);
    exit;
}

// Configurar el cliente API de Inmovilla con variables de entorno
try {
    // Crear la configuración desde variables de entorno
    $config = ApiClientConfig::fromArray([
        'AGENCY' => getenv('INMOVILLA_AGENCY'),
        'PASSWORD' => getenv('INMOVILLA_PASSWORD'),
        'LANGUAGE' => getenv('INMOVILLA_LANGUAGE') ?: 1,
        'API_URL' => getenv('INMOVILLA_API_URL') ?: 'https://api.inmovilla.com/v1',
        'DOMAIN' => getenv('INMOVILLA_DOMAIN')
    ]);
    
    // Crear instancias necesarias
    $httpClient = new GuzzleClient();
    $requestFactory = new HttpFactory();
    $client = ApiClientFactory::createFromConfig($config, $httpClient, $requestFactory);
    
    // Crear repositorios
    $propertyRepository = new PropiedadRepository($client);
    $propertyDetailsRepository = new PropiedadFichaRepository($client);
    
} catch (Exception $e) {
    sendError("Error de configuración: " . $e->getMessage(), 500);
}

// Obtener el path de la URL y quitar el directorio base si existe
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$baseFolder = dirname($_SERVER['SCRIPT_NAME']);

// Si la aplicación no está en la raíz, quitar el prefijo de la ruta base
if ($baseFolder !== '/' && strpos($requestUri, $baseFolder) === 0) {
    $requestUri = substr($requestUri, strlen($baseFolder));
}

// Dividir la ruta en segmentos
$uri = explode('/', trim($requestUri, '/'));

// Procesar la solicitud según la ruta
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Ejemplo: /property/{reference}
        if (isset($uri[0]) && $uri[0] === 'property' && isset($uri[1])) {
            getPropertyByReference($uri[1]);
        }
        // Ejemplo: /search?ref=XXX o /search?street=XXX
        elseif (isset($uri[0]) && $uri[0] === 'search') {
            searchProperties($_GET);
        }
        else {
            sendError("Ruta no encontrada", 404);
        }
        break;
        
    case 'POST':
        // Procesar datos POST
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Ejemplo: /search
        if (isset($uri[0]) && $uri[0] === 'search') {
            searchProperties($data);
        } 
        else {
            sendError("Ruta no encontrada", 404);
        }
        break;
        
    default:
        sendError("Método no permitido", 405);
        break;
}

/**
 * Busca propiedades según los parámetros proporcionados
 */
function searchProperties($params) {
    global $propertyRepository;
    
    // Construir condiciones de búsqueda
    $whereConditions = [];
    
    // Buscar por referencia
    if (isset($params['reference']) && !empty($params['reference'])) {
        $whereConditions['ref'] = $params['reference'];
    }
    
    // Buscar por calle
    if (isset($params['street']) && !empty($params['street'])) {
        // Inmovilla podría tener un campo específico para búsqueda por calle
        // O podría ser parte de un campo de dirección completa
        $whereConditions['direccion'] = $params['street'];
    }
    
    // Si no hay parámetros de búsqueda
    if (empty($whereConditions)) {
        sendError("Se requiere al menos un parámetro de búsqueda (reference o street)");
    }
    
    try {
        // Construir la cláusula WHERE
        $whereClause = '';
        foreach ($whereConditions as $field => $value) {
            if (!empty($whereClause)) {
                $whereClause .= ' AND ';
            }
            $whereClause .= $field . '="' . addslashes($value) . '"';
        }
        
        // Realizar la búsqueda (valores predeterminados: posición inicial 1, número de elementos 10)
        $startPosition = isset($params['start']) ? intval($params['start']) : 1;
        $numElements = isset($params['limit']) ? intval($params['limit']) : 10;
        
        // Ejecutar la consulta usando el repositorio
        $properties = $propertyRepository->findAll($startPosition, $numElements, $whereClause);
        
        // Devolver los resultados
        echo json_encode([
            'success' => true,
            'total' => $properties->total,
            'results' => $properties->items,
        ]);
        
    } catch (Exception $e) {
        sendError("Error al buscar propiedades: " . $e->getMessage(), 500);
    }
}

/**
 * Obtiene los detalles de una propiedad por su referencia
 */
function getPropertyByReference($reference) {
    global $propertyRepository, $propertyDetailsRepository;
    
    if (empty($reference)) {
        sendError("Se requiere una referencia de propiedad");
    }
    
    try {
        // Primero buscar la propiedad para obtener su cod_ofer
        $whereClause = 'ref="' . addslashes($reference) . '"';
        $properties = $propertyRepository->findAll(1, 1, $whereClause);
        
        if ($properties->total === 0 || empty($properties->items)) {
            sendError("No se encontró ninguna propiedad con la referencia: " . $reference, 404);
        }
        
        // Obtener la primera propiedad encontrada
        $property = $properties->items[0];
        
        // Obtener detalles completos usando el cod_ofer
        $details = $propertyDetailsRepository->findOneByCodOffer($property->cod_ofer);
        
        // Devolver los detalles
        echo json_encode([
            'success' => true,
            'property' => $details
        ]);
        
    } catch (Exception $e) {
        sendError("Error al obtener detalles de la propiedad: " . $e->getMessage(), 500);
    }
}
