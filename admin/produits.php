<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$db = get_db();
$errors = [];

const PRODUCT_UPLOAD_DIR = __DIR__ . '/../assets/uploads/products/';
const PRODUCT_UPLOAD_WEB_PATH = 'assets/uploads/products/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $stmt = $db->prepare('SELECT image FROM products WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['id']]);
    $row = $stmt->fetch();

    $stmt = $db->prepare('DELETE FROM products WHERE id = :id');
    $stmt->execute(['id' => (int) $_POST['id']]);

    if ($row) {
        delete_uploaded_image($row['image']);
    }

    header('Location: /market/admin/produits.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $shopId = (int) ($_POST['shop_id'] ?? 0);
    $price = (int) ($_POST['price'] ?? 0);
    $originalPriceRaw = trim((string) ($_POST['original_price'] ?? ''));
    $originalPrice = $originalPriceRaw !== '' ? (int) $originalPriceRaw : null;
    $stock = max(0, (int) ($_POST['stock'] ?? 0));
    $productIcon = trim((string) ($_POST['icon'] ?? '')) ?: 'box';
    $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $removeImage = isset($_POST['remove_image']);

    $sizeType = in_array($_POST['size_type'] ?? 'none', ['none', 'clothing', 'shoe'], true) ? $_POST['size_type'] : 'none';
    $sizeStocks = [];
    if ($sizeType !== 'none') {
        foreach (product_size_options($sizeType) as $sizeOption) {
            $qty = max(0, (int) ($_POST['size_stock'][$sizeOption] ?? 0));
            if ($qty > 0) {
                $sizeStocks[$sizeOption] = $qty;
            }
        }
        if (!$sizeStocks) {
            $errors['size_type'] = 'Ajoutez au moins une taille avec un stock disponible.';
        }
        $stock = array_sum($sizeStocks);
    }

    $currentImage = null;
    if ($id > 0) {
        $stmt = $db->prepare('SELECT image FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $currentImage = $stmt->fetchColumn() ?: null;
    }

    if ($name === '') {
        $errors['name'] = 'Veuillez indiquer un nom.';
    }
    if ($categoryId <= 0) {
        $errors['category_id'] = 'Veuillez choisir une catégorie.';
    }
    if ($shopId <= 0) {
        $errors['shop_id'] = 'Veuillez choisir une boutique.';
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
                SET name = :name, slug = :slug, description = :description, category_id = :category_id, shop_id = :shop_id,
                    price = :price, original_price = :original_price, stock = :stock, size_type = :size_type, icon = :icon, image = :image,
                    is_featured = :is_featured, sort_order = :sort_order
                WHERE id = :id
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'description' => $description !== '' ? $description : null, 'category_id' => $categoryId, 'shop_id' => $shopId,
                'price' => $price, 'original_price' => $originalPrice, 'stock' => $stock, 'size_type' => $sizeType, 'icon' => $productIcon, 'image' => $finalImage,
                'is_featured' => $isFeatured, 'sort_order' => $sortOrder, 'id' => $id,
            ]);
            $productId = $id;
        } else {
            $stmt = $db->prepare('
                INSERT INTO products (name, slug, description, category_id, shop_id, price, original_price, stock, size_type, icon, image, is_featured, sort_order)
                VALUES (:name, :slug, :description, :category_id, :shop_id, :price, :original_price, :stock, :size_type, :icon, :image, :is_featured, :sort_order)
            ');
            $stmt->execute([
                'name' => $name, 'slug' => $slug, 'description' => $description !== '' ? $description : null, 'category_id' => $categoryId, 'shop_id' => $shopId,
                'price' => $price, 'original_price' => $originalPrice, 'stock' => $stock, 'size_type' => $sizeType, 'icon' => $productIcon, 'image' => $finalImage,
                'is_featured' => $isFeatured, 'sort_order' => $sortOrder,
            ]);
            $productId = (int) $db->lastInsertId();
        }

        $db->prepare('DELETE FROM product_sizes WHERE product_id = :id')->execute(['id' => $productId]);
        if ($sizeStocks) {
            $insertSize = $db->prepare('INSERT INTO product_sizes (product_id, size, stock, sort_order) VALUES (:product_id, :size, :stock, :sort_order)');
            $order = 0;
            foreach (product_size_options($sizeType) as $sizeOption) {
                if (isset($sizeStocks[$sizeOption])) {
                    $insertSize->execute(['product_id' => $productId, 'size' => $sizeOption, 'stock' => $sizeStocks[$sizeOption], 'sort_order' => $order]);
                }
                $order++;
            }
        }

        header('Location: /market/admin/produits.php');
        exit;
    }
}

$categories = $db->query('SELECT id, name FROM categories ORDER BY sort_order')->fetchAll();
$shops = $db->query('SELECT id, name FROM shops ORDER BY sort_order')->fetchAll();

$editing = null;
$editingSizeStocks = [];
$formAction = (string) ($_GET['action'] ?? '');

if ($formAction === 'new') {
    $editing = [
        'id' => 0, 'name' => '', 'description' => '', 'category_id' => '', 'shop_id' => '',
        'price' => '', 'original_price' => '', 'stock' => 0, 'size_type' => 'none', 'icon' => '', 'image' => null, 'is_featured' => 0, 'sort_order' => 0,
    ];
} elseif ($formAction === 'edit' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editing = $stmt->fetch() ?: null;

    if ($editing) {
        $stmt = $db->prepare('SELECT size, stock FROM product_sizes WHERE product_id = :id ORDER BY sort_order');
        $stmt->execute(['id' => (int) $editing['id']]);
        foreach ($stmt->fetchAll() as $row) {
            $editingSizeStocks[$row['size']] = (int) $row['stock'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $editing = $_POST;
    $editing['image'] = $currentImage ?? null;
    $editingSizeStocks = $_POST['size_stock'] ?? [];
}

$pageTitle = $editing ? (($editing['id'] ?? 0) ? 'Modifier le produit' : 'Nouveau produit') : 'Produits';
require_once __DIR__ . '/../includes/admin_header.php';

if ($editing):
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2><?= ($editing['id'] ?? 0) ? 'Modifier le produit' : 'Nouveau produit' ?></h2>
            <a href="/market/admin/produits.php" class="link-more"><?= icon('chevron-right', 14) ?> Retour à la liste</a>
        </div>

        <form method="post" action="/market/admin/produits.php" enctype="multipart/form-data" novalidate>
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
                <div class="form-field <?= isset($errors['shop_id']) ? 'has-error' : '' ?>">
                    <label for="shop_id">Boutique *</label>
                    <select id="shop_id" name="shop_id" required>
                        <option value="">Choisir...</option>
                        <?php foreach ($shops as $shop): ?>
                            <option value="<?= (int) $shop['id'] ?>" <?= (string) ($editing['shop_id'] ?? '') === (string) $shop['id'] ? 'selected' : '' ?>><?= e($shop['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['shop_id'])): ?><span class="field-error"><?= e($errors['shop_id']) ?></span><?php endif; ?>
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
                <div class="form-field" id="stock-field-global">
                    <label for="stock">Stock disponible *</label>
                    <input type="number" id="stock" name="stock" min="0" value="<?= e((string) ($editing['stock'] ?? 0)) ?>" required>
                    <span class="char-count">0 = rupture de stock, la commande sera bloquée.</span>
                </div>
            </div>

            <div class="form-field <?= isset($errors['size_type']) ? 'has-error' : '' ?>">
                <label for="size_type">Type de taille</label>
                <select id="size_type" name="size_type">
                    <option value="none" <?= (string) ($editing['size_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>Aucune (produit sans taille)</option>
                    <option value="clothing" <?= (string) ($editing['size_type'] ?? '') === 'clothing' ? 'selected' : '' ?>>Vêtement (XXS à 5XL)</option>
                    <option value="shoe" <?= (string) ($editing['size_type'] ?? '') === 'shoe' ? 'selected' : '' ?>>Chaussure (pointure 20 à 50)</option>
                </select>
                <span class="char-count">Si une taille est choisie, le client devra sélectionner une taille à la commande. Le stock global ci-dessus sera alors calculé automatiquement (somme des stocks par taille).</span>
                <?php if (isset($errors['size_type'])): ?><span class="field-error"><?= e($errors['size_type']) ?></span><?php endif; ?>
            </div>

            <div class="form-field" id="size-stock-clothing" style="display:none;">
                <label>Stock par taille (vêtement)</label>
                <div class="admin-size-grid">
                    <?php foreach (CLOTHING_SIZES as $sizeOption): ?>
                        <div class="admin-size-grid-item">
                            <label for="size_stock_<?= e($sizeOption) ?>"><?= e($sizeOption) ?></label>
                            <input type="number" id="size_stock_<?= e($sizeOption) ?>" name="size_stock[<?= e($sizeOption) ?>]" min="0" value="<?= (int) ($editingSizeStocks[$sizeOption] ?? 0) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-field" id="size-stock-shoe" style="display:none;">
                <label>Stock par pointure (chaussure)</label>
                <div class="admin-size-grid">
                    <?php foreach (shoe_sizes() as $sizeOption): ?>
                        <div class="admin-size-grid-item">
                            <label for="size_stock_<?= e($sizeOption) ?>"><?= e($sizeOption) ?></label>
                            <input type="number" id="size_stock_<?= e($sizeOption) ?>" name="size_stock[<?= e($sizeOption) ?>]" min="0" value="<?= (int) ($editingSizeStocks[$sizeOption] ?? 0) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <script>
                (function () {
                    var sizeTypeSelect = document.getElementById('size_type');
                    var stockGlobalField = document.getElementById('stock-field-global');
                    var stockInput = document.getElementById('stock');
                    var grids = {
                        clothing: document.getElementById('size-stock-clothing'),
                        shoe: document.getElementById('size-stock-shoe'),
                    };

                    function refresh() {
                        var type = sizeTypeSelect.value;
                        grids.clothing.style.display = type === 'clothing' ? '' : 'none';
                        grids.shoe.style.display = type === 'shoe' ? '' : 'none';
                        if (type === 'none') {
                            stockGlobalField.style.display = '';
                            stockInput.removeAttribute('readonly');
                        } else {
                            stockGlobalField.style.display = 'none';
                        }
                    }

                    sizeTypeSelect.addEventListener('change', refresh);
                    refresh();
                })();
            </script>

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

            <div class="form-row">
                <div class="form-field">
                    <label for="icon">Icône (repli si pas d'image)</label>
                    <input type="text" id="icon" name="icon" value="<?= e((string) ($editing['icon'] ?? '')) ?>" placeholder="wheat, droplet, smartphone...">
                </div>
                <div class="form-field">
                    <label for="sort_order">Ordre d'affichage</label>
                    <input type="number" id="sort_order" name="sort_order" value="<?= e((string) ($editing['sort_order'] ?? 0)) ?>">
                </div>
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
    $pagination = paginate((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn(), 20);
    $stmt = $db->prepare('
        SELECT p.*, c.name AS category_name, s.name AS shop_name
        FROM products p
        JOIN categories c ON c.id = p.category_id
        JOIN shops s ON s.id = p.shop_id
        ORDER BY p.sort_order
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();
?>

    <div class="card">
        <div class="admin-toolbar">
            <h2>Produits (<?= $pagination['total_items'] ?>)</h2>
            <a href="/market/admin/produits.php?action=new" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Nouveau produit</a>
        </div>

        <?php if (!$products): ?>
            <p class="empty-state">Aucun produit pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Produit</th>
                            <th>Catégorie</th>
                            <th>Boutique</th>
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
                                <td><?= e($p['category_name']) ?></td>
                                <td><?= e($p['shop_name']) ?></td>
                                <td>
                                    <?= format_price((int) $p['price']) ?>
                                    <?php if ($p['original_price']): ?><br><span class="price-old"><?= format_price((int) $p['original_price']) ?></span><?php endif; ?>
                                </td>
                                <td><?= (int) $p['stock'] > 0 ? (int) $p['stock'] : '<span class="tag tag-closed">Rupture</span>' ?></td>
                                <td><?= $p['is_featured'] ? icon('check', 16) : '—' ?></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="/market/admin/produit-detail.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Détail</a>
                                        <a href="/market/admin/produits.php?action=edit&id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Modifier</a>
                                        <form method="post" action="/market/admin/produits.php" onsubmit="return confirm('Supprimer ce produit ?');">
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
            <?= pagination_html($pagination['page'], $pagination['total_pages'], '/market/admin/produits.php') ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
