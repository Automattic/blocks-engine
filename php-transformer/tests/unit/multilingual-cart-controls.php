<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

$recognizer = new CommerceStructureRecognizer();
foreach (array(
    array('<button>Add to cart</button>', true),
    array('<button>Add to basket</button>', true),
    array('<button>Buy now</button>', true),
    array('<a href="/cart">View cart</a>', false),
    array('<button aria-label="Agregar Crema al carrito">Agregar</button>', true),
    array('<button>Añadir a la cesta</button>', true),
    array('<button>Ajouter au panier</button>', true),
    array('<button>In den Warenkorb</button>', true),
    array('<button>Hinzufügen zum Warenkorb</button>', true),
    array('<button>Adicionar ao carrinho</button>', true),
    array('<button>Aggiungi al carrello</button>', true),
    array('<button>Toevoegen aan winkelwagen</button>', true),
    array('<button>Comprar</button>', true),
    array('<button>Acheter</button>', true),
    array('<button>Kaufen</button>', true),
    array('<button>Acquista</button>', true),
    array('<button>Kopen</button>', true),
    array('<a href="/carrito">Ver carrito</a>', false),
    array('<a href="/panier">Voir le panier</a>', false),
    array('<button>Añadir</button>', false),
    array('<button>preañadir basket</button>', false),
    array('<button>Warenkorbartikel</button>', false),
) as [$control, $expected]) {
    $document = HtmlSerialization::parseDocument('<!doctype html><html><head><meta charset="utf-8"></head><body><article>' . $control . '</article></body></html>');
    $card = $document->getElementsByTagName('article')->item(0);
    if ($expected !== (null !== $recognizer->cartControlElement($card))) {
        throw new RuntimeException('Unexpected cart recognition: ' . $control);
    }
}
echo "multilingual-cart-controls: ok\n";
