# Plugin de Bloque Moodle / IOMAD: Mis Categorías y Cursos (Multitenant)
**Componente:** `block_iomad_category_courses`  
**Versión:** `v1.0.0-iomad` (`2026100100`)

---

## Características Principales

1. **Aislamiento Multitenant de IOMAD (Zero Branding Leakage):**
   - Detecta la compañía activa a la que pertenece el usuario (`iomad::get_user_iomad_company`).
   - Si un curso matriculado pertenece a otra compañía o a una categoría externa compartida (ej. *Tigo Ventas*), el plugin **enmascara automáticamente el nombre** y lo agrupa bajo un título seguro y neutral (por defecto: **"Cursos Transversales"**).

2. **Desglose de Subcategorías Temáticas de la Compañía:**
   - Si la empresa tiene subcategorías temáticas dentro de su categoría raíz (ej: *CFA ALT > Inducción*, *CFA ALT > Ventas*), el plugin **omite la tarjeta redundante con el nombre de la empresa** y muestra directamente las subcategorías temáticas como tarjetas individuales.
   - Para los cursos alojados en la raíz de la empresa, utiliza un alias corporativo limpio (por defecto: **"Formación Institucional"**).

3. **Gestión Avanzada de Imágenes:**
   - Soporte para subir imágenes en el bloque vinculadas por:
     - ID de categoría Moodle (ej. `10.jpg`, `cat_10.png`).
     - Nombre temático limpio (ej. `Inducción.jpg`, `Ventas.png`).
     - Imagen especial para cursos transversales (`transversal.jpg` o `compartido.jpg`).
     - Imagen general de respaldo (`default.jpg`).
   - Las tarjetas sin imagen generan automáticamente un banner con degradado de color y las iniciales de la sección.

---

## Instalación en Moodle / IOMAD

1. Descarga el paquete `iomad_category_courses.zip`.
2. Súbelo en **Administración del sitio > Extensiones > Instalar extensiones** (tipo: Bloque).
3. Entra a **Administración del sitio > Notificaciones** para completar la instalación.
4. Añade el bloque en el Área Personal (`/my/`) o en el tablero de IOMAD.
