<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

$vendorUser = require_vendor();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}
$vendorShop = current_vendor_shop((int) $vendorUser['id']);

if (!$vendorShop) {
    header('Location: /market/vendeur/index.php');
    exit;
}

const PRODUCT_UPLOAD_DIR = __DIR__ . '/../assets/uploads/products/';
const PRODUCT_UPLOAD_WEB_PATH = 'assets/uploads/products/';

$db = get_db();
$shopId = (int) $vendorShop['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $db->prepare('SELECT image FROM products WHERE id = :id AND shop_id = :shop_id');
    $stmt->execute(['id' => (int) $_POST['id'], 'shop_id' => $shopId]);
    $row = $stmt->fetch();

    if ($row) {
        $stmt = $db->prepare('DELETE FROM products WHERE id = :id AND shop_id = :shop_id');
        $stmt->execute(['id' => (int) $_POST['id'], 'shop_id' => $shopId]);
        delete_uploaded_image($row['image']);
    }

    header('Location: /market/vendeur/produits.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $price = (int) ($_POST['price'] ?? 0);
    $originalPriceRaw = trim((string) ($_POST['original_price'] ?? ''));
    $originalPrice = $originalPriceRaw !== '' ? (int) $originalPriceRaw : null;
    $stock = max(0, (int) ($_POST['stock'] ?? 0));
    $productIcon = trim((string) ($_POST['icon'] ?? '')) ?: 'box';
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $removeImage = isset($_POST['remove_image']);

    $currentImage = null;
    if ($id > 0) {
        $stmt = $db->prepare('SELECT image FROM products WHERE id = :id AND shop_id = :shop_id');
        $stmt->execute(['id' => $id, 'shop_id' => $shopId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            $id = 0;
        } else {
            $currentImage = $existing['image'] ?: null;
        }
    }

    if ($name === '') {
        $errors['name'] = 'Veuillez indiquer un nom.';
    }
    if ($categoryId <= 0) {
        $errors['category_id'] = 'Veuillez choisir une catégorie.';
    }
    if ($price <= 0) {
        $errors['price'] = 'Le prix doit être supérieur à 0.';
    }
    if ($originalPrice !== null && $originalPrice <= $price) {
        $errors['original_price'] = "Le prix barré doit être supérieur au prix actuel.";
    }

    $newImageExt = null;
    $hasUpload = isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload) {
        $validation = validate_uploaded_image($_FILES['image']);
        if ($validation['error']) {
            $errors['image'] = $validation['error'];
        } else {
            $newImageExt = $validation['ext'];
        }
    }

    if (!$errors) {
        $baseSlug = slugify($name) ?: 'produit';
        $slug = $baseSlug;
        $suffix = 2;
        while (true) {
            $stmt = $db->prepare('SELECT id FROM products WHERE slug = :slug AND id != :id');
            $stmt->execute(['slug' => $slug, 'id' => $id]);
            if (!$stmt->fetch()) {
                break;
            }
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        if ($newImageExt) {
            $finalImage = store_uploaded_image($_FILES['image'], $newImageExt, PRODUCT_UPLOAD_DIR, PRODUCT_UPLOAD_WEB_PATH);
            delete_uploaded_image($currentImage);
        } elseif ($removeImage) {
            delete_uploaded_image($currentImage);
            $finalImage = null;
        } else {
            $finalImage = $currentImage;
        }

        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE products
                SET name = :name, slug = :slug, description = :description, category_id = :category_id,
                    price = :price, original_price = :original_price, stock = :stock, icon = :icon, image = :image,
                    is_featured = :is_featured
                WHERE id = :id AND shop_id = :shop_id
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'description' => $description !== '' ? $description : null, 'category_id' => $categoryId,
                'price' => $price, 'original_price' => $originalPrice, 'stock' => $stock, 'icon' => $productIcon, 'image' => $finalImage,
                'is_featured' => $isFeatured, 'id' => $id, 'shop_id' => $shopId,
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO products (name, slug, description, category_id, shop_id, price, original_price, stock, icon, image, is_featured)
                VALUES (:name, :slug, :description, :category_id, :shop_id, :price, :original_price, :stock, :icon, :image, :is_featured)
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'description' => $description !== '' ? $description : null, 'category_id' => $categoryId, 'shop_id' => $shopId,
                'price' => $price, 'original_price' => $originalPrice, 'stock' => $stock, 'icon' => $productIcon, 'image' => $finalImage,
                'is_featured' => $isFeatured,
            ]);
        }

        header('Location: /market/vendeur/produits.php');
        exit;
    }
}

$categories = $db->query('SELECT id, name FROM categories ORDER BY sort_order')->fetchAll();

$editing = null;
$formAction = (string) ($_GET['action'] ?? '');

if ($formAction === 'new') {
    $editing = [
        'id' => 0, 'name' => '', 'description' => '', 'category_id' => '',
        'price' => '', 'original_price' => '', 'stock' => 0, 'icon' => '', 'image' => null, 'is_featured' => 0,
    ];
} elseif ($formAction === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id = :id AND shop_id = :shop_id');
    $stmt->execute(['id' => (int) $_GET['id'], 'shop_id' => $shopId]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
    $editing['image'] = $currentImage ?? null;
}

$pageTitle = $editing ? (($editing['id'] ?? 0) ? 'Modifier le produit' : 'Nouveau produit') : 'Mes produits';
require_once __DIR__ . '/../includes/vendor_header.php';
?>

<?php if ($editing): ?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? 'Modifier le produit' : 'Nouveau produit' ?></h2>
            <a href="/market/vendeur/produits.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <form method="post" action="/market/vendeur/produits.php" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="form-field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label for="name">Nom du produit *</label>
                <input type="text" id="name" name="name" value="<?= e((string) ($editing['name'] ?? '')) ?>" required>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-field">
                <label for="description">Description (optionnelle, visible par le client sur la fiche produit)</label>
                <textarea id="description" name="description" rows="4" placeholder="Décrivez le produit : caractéristiques, composition, utilisation..."><?= e((string) ($editing['description'] ?? '')) ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['category_id']) ? 'has-error' : '' ?>">
                    <label for="category_id">Catégorie *</label>
                    <select id="category_id" name="category_id" required>
                        <option value="">Choisir...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= (string) ($editing['category_id'] ?? '') === (string) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['category_id'])): ?><span class="field-error"><?= e($errors['category_id']) ?></span><?php endif; ?>
                </div>
                <div class="form-field">
                    <label>Boutique</label>
                    <input type="text" value="<?= e((string) $vendorShop['name']) ?>" disabled>
                </div>
            </div>

            <div class="form-row">
                <div class="form-field <?= isset($errors['price']) ? 'has-error' : '' ?>">
                    <label for="price">Prix (FCFA) *</label>
                    <input type="number" id="price" name="price" min="1" value="<?= e((string) ($editing['price'] ?? '')) ?>" required>
                    <?php if (isset($errors['price'])): ?><span class="field-error"><?= e($errors['price']) ?></span><?php endif; ?>
                </div>
                <div class="form-field <?= isset($errors['original_price']) ? 'has-error' : '' ?>">
                    <label for="original_price">Prix barré (optionnel)</label>
                    <input type="number" id="original_price" name="original_price" min="1" value="<?= e((string) ($editing['original_price'] ?? '')) ?>">
                    <?php if (isset($errors['original_price'])): ?><span class="field-error"><?= e($errors['original_price']) ?></span><?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="stock">Stock disponible *</label>
                    <input type="number" id="stock" name="stock" min="0" value="<?= e((string) ($editing['stock'] ?? 0)) ?>" required>
                    <span class="char-count">0 = rupture de stock, la commande sera bloquée.</span>
                </div>
            </div>

            <div class="form-field <?= isset($errors['image']) ? 'has-error' : '' ?>">
                <label for="image">Image du produit</label>
                <?php if (!empty($editing['image'])): ?>
                    <div class="admin-image-preview">
                        <img src="/market/<?= e((string) $editing['image']) ?>" alt="">
                        <label class="filter-toggle">
                            <input type="checkbox" name="remove_image" value="1">
                            <span>Supprimer l'image actuelle</span>
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                <span class="char-count">JPG, PNG, WEBP ou GIF — 3 Mo max. Sans image, l'icône ci-dessous est utilisée à la place.</span>
                <?php if (isset($errors['image'])): ?><span class="field-error"><?= e($errors['image']) ?></span><?php endif; ?>
            </div>

            <div class="form-field">
                <label for="icon">Icône (repli si pas d'image)</label>
                <input type="text" id="icon" name="icon" value="<?= e((string) ($editing['icon'] ?? '')) ?>" placeholder="wheat, droplet, smartphone...">
            </div>

            <div class="form-field">
                <label class="filter-toggle">
                    <input type="checkbox" name="is_featured" value="1" <?= !empty($editing['is_featured']) ? 'checked' : '' ?>>
                    <span>Mettre en avant (page d'accueil / offres)</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>

<?php else:
    $countStmt = $db->prepare('SELECT COUNT(*) FROM products WHERE shop_id = :shop_id');
    $countStmt->execute(['shop_id' => $shopId]);
    $pagination = paginate((int) $countStmt->fetchColumn(), 20);

    $products = $db->prepare('
        SELECT p.*, c.name AS category_name
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.shop_id = :shop_id
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset
    ');
    $products->bindValue('shop_id', $shopId, PDO::PARAM_INT);
    $products->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
    $products->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $products->execute();
    $products = $products->fetchAll();
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Mes produits (<?= $pagination['total_items'] ?>)</h2>
            <a href="/market/vendeur/produits.php?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouveau produit</a>
        </div>

        <?php if (!$products): ?>
            <p class="empty-state">Aucun produit pour le moment. <a href="/market/vendeur/produits.php?action=new">Ajouter mon premier produit</a></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Produit</th>
                            <th>Catégorie</th>
                            <th>Prix</th>
                            <th>Stock</th>
                            <th>Vedette</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><div class="product-thumb admin-table-thumb"><?= product_thumb_html($p, 20) ?></div></td>
                                <td><?= e($p['name']) ?></td>
                                <td><a href="/market/vendeur/categorie-detail.php?id=<?= (int) $p['category_id'] ?>" class="link-muted"><?= e($p['category_name']) ?></a></td>
                                <td>
                                    <?= format_price((int) $p['price']) ?>
                                    <?php if ($p['original_price']): ?><br><span class="price-old"><?= format_price((int) $p['original_price']) ?></span><?php endif; ?>
                                </td>
                                <td><?= (int) $p['stock'] > 0 ? (int) $p['stock'] : '<span class="tag tag-closed">Rupture</span>' ?></td>
                                <td><?= $p['is_featured'] ? icon('check', 16) : '—' ?></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="/market/vendeur/produit-detail.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                        <a href="/market/vendeur/produits.php?action=edit&id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                        <form method="post" action="/market/vendeur/produits.php" onsubmit="return confirm('Supprimer ce produit ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="btn btn-outline-primary btn-sm">Supprimer</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= pagination_html($pagination['page'], $pagination['total_pages'], '/market/vendeur/produits.php') ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/vendor_footer.php'; ?>
