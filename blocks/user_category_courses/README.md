# Plugin de Bloque Moodle / Iomad: Mis Categorías y Cursos (v1.4.3)

## Correcciones aplicadas en v1.4.3:
1. Corrección en `block_user_category_courses.php`: ahora comprueba `$data->categoryimages` (el nombre real tras procesar Formslib) para invocar `file_save_draft_area_files()` y persistir las imágenes subidas al guardar la configuración.
2. Corrección en `lib.php`: se implementa `array_shift($args)` para extraer `$itemid`, garantizando que la ruta de almacenamiento (`filepath`) sea `'/'` y Moodle sirva las imágenes correctamente sin errores 404.
3. Soporte ampliado en `helper.php` para vincular imágenes tanto por número de ID (`1.jpg`) como por nombre de categoría limpio (`Category 1.jpg`).
4. Archivos de idioma y sanitización de variables blindados para PHP 8.x.
