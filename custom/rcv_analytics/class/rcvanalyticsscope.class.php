<?php
/* Copyright (C) 2024 DatiLab
 * Gestión de roles/alcance para RCV Analytics.
 *
 * Un "rol" es un perfil reutilizable de alcance de datos: define a qué
 * programas y/o medicamentos puede ver un usuario en las analíticas.
 *  - Si el rol tiene all_access=1  → acceso total (sin restricción).
 *  - Si no, restringe a los programas y medicamentos asociados.
 *
 * El alcance efectivo de un usuario es la UNIÓN de todos sus roles.
 * Un usuario admin, o sin ningún rol asignado, ve todo (compatibilidad).
 */
class RcvAnalyticsScope
{
    const TBL_ROLE      = 'rcv_analytics_role';
    const TBL_ROLE_PROG = 'rcv_analytics_role_programa';
    const TBL_ROLE_MED  = 'rcv_analytics_role_medicamento';
    const TBL_USER_ROLE = 'rcv_analytics_user_role';

    /** @var array Caché por request del alcance resuelto, indexado por id de usuario */
    private static $scopeCache = array();

    /**
     * Crea las tablas del sistema de roles si no existen (idempotente).
     * Se llama desde las páginas de administración para no requerir reactivar el módulo.
     *
     * @param DoliDB $db
     * @return void
     */
    public static function ensureTables($db)
    {
        $p = MAIN_DB_PREFIX;

        $db->query("CREATE TABLE IF NOT EXISTS ".$p.self::TBL_ROLE." (
            rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(255) NOT NULL,
            description TEXT NULL,
            all_access TINYINT DEFAULT 0,
            entity INTEGER DEFAULT 1,
            datec DATETIME,
            tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            fk_user_creat INTEGER
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS ".$p.self::TBL_ROLE_PROG." (
            rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
            fk_role INTEGER NOT NULL,
            fk_programa INTEGER NOT NULL,
            UNIQUE KEY uk_role_prog (fk_role, fk_programa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS ".$p.self::TBL_ROLE_MED." (
            rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
            fk_role INTEGER NOT NULL,
            fk_medicamento INTEGER NOT NULL,
            UNIQUE KEY uk_role_med (fk_role, fk_medicamento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS ".$p.self::TBL_USER_ROLE." (
            rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
            fk_user INTEGER NOT NULL,
            fk_role INTEGER NOT NULL,
            UNIQUE KEY uk_user_role (fk_user, fk_role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // -------------------------------------------------------------------------
    // CRUD de roles
    // -------------------------------------------------------------------------

    /**
     * Lista los roles de la entidad con contadores.
     *
     * @param  DoliDB $db
     * @param  int    $entity
     * @return array  [['rowid','label','all_access','nb_prog','nb_med','nb_user'], ...]
     */
    public static function getRoles($db, $entity)
    {
        $p = MAIN_DB_PREFIX;
        $sql = "SELECT r.rowid, r.label, r.all_access,"
            ." (SELECT COUNT(*) FROM ".$p.self::TBL_ROLE_PROG." rp WHERE rp.fk_role = r.rowid) AS nb_prog,"
            ." (SELECT COUNT(*) FROM ".$p.self::TBL_ROLE_MED." rm WHERE rm.fk_role = r.rowid) AS nb_med,"
            ." (SELECT COUNT(*) FROM ".$p.self::TBL_USER_ROLE." ru WHERE ru.fk_role = r.rowid) AS nb_user"
            ." FROM ".$p.self::TBL_ROLE." r"
            ." WHERE r.entity = ".((int) $entity)
            ." ORDER BY r.label ASC";

        return self::fetchAll($db, $sql);
    }

    /**
     * Carga un rol individual.
     *
     * @param  DoliDB $db
     * @param  int    $id
     * @return array|null
     */
    public static function getRole($db, $id)
    {
        $sql = "SELECT rowid, label, description, all_access, entity"
            ." FROM ".MAIN_DB_PREFIX.self::TBL_ROLE
            ." WHERE rowid = ".((int) $id);
        $rows = self::fetchAll($db, $sql);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Crea un rol y devuelve su id, o <0 en error.
     */
    public static function createRole($db, $user, $label, $all_access, $description = '')
    {
        $p = MAIN_DB_PREFIX;
        $now = dol_now();
        $sql = "INSERT INTO ".$p.self::TBL_ROLE." (label, description, all_access, entity, datec, fk_user_creat)"
            ." VALUES ('".$db->escape($label)."', '".$db->escape($description)."', ".($all_access ? 1 : 0).", "
            .((int) $GLOBALS['conf']->entity).", '".$db->idate($now)."', ".((int) $user->id).")";
        if ($db->query($sql)) {
            return (int) $db->last_insert_id($p.self::TBL_ROLE);
        }
        return -1;
    }

    /**
     * Actualiza etiqueta / acceso total de un rol.
     */
    public static function updateRole($db, $id, $label, $all_access, $description = '')
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX.self::TBL_ROLE
            ." SET label = '".$db->escape($label)."',"
            ." description = '".$db->escape($description)."',"
            ." all_access = ".($all_access ? 1 : 0)
            ." WHERE rowid = ".((int) $id);
        return $db->query($sql) ? 1 : -1;
    }

    /**
     * Elimina un rol y todas sus asociaciones.
     */
    public static function deleteRole($db, $id)
    {
        $id = (int) $id;
        $p = MAIN_DB_PREFIX;
        $db->query("DELETE FROM ".$p.self::TBL_ROLE_PROG." WHERE fk_role = ".$id);
        $db->query("DELETE FROM ".$p.self::TBL_ROLE_MED." WHERE fk_role = ".$id);
        $db->query("DELETE FROM ".$p.self::TBL_USER_ROLE." WHERE fk_role = ".$id);
        return $db->query("DELETE FROM ".$p.self::TBL_ROLE." WHERE rowid = ".$id) ? 1 : -1;
    }

    // -------------------------------------------------------------------------
    // Asociaciones rol ↔ programas / medicamentos / usuarios
    // -------------------------------------------------------------------------

    public static function getRoleProgramaIds($db, $id)
    {
        return self::fetchIds($db, "SELECT fk_programa AS id FROM ".MAIN_DB_PREFIX.self::TBL_ROLE_PROG." WHERE fk_role = ".((int) $id));
    }

    public static function getRoleMedicamentoIds($db, $id)
    {
        return self::fetchIds($db, "SELECT fk_medicamento AS id FROM ".MAIN_DB_PREFIX.self::TBL_ROLE_MED." WHERE fk_role = ".((int) $id));
    }

    public static function getRoleUserIds($db, $id)
    {
        return self::fetchIds($db, "SELECT fk_user AS id FROM ".MAIN_DB_PREFIX.self::TBL_USER_ROLE." WHERE fk_role = ".((int) $id));
    }

    /**
     * Reemplaza el conjunto de programas de un rol.
     */
    public static function setRoleProgramas($db, $id, array $ids)
    {
        return self::replaceLinks($db, self::TBL_ROLE_PROG, 'fk_programa', $id, $ids);
    }

    /**
     * Reemplaza el conjunto de medicamentos de un rol.
     */
    public static function setRoleMedicamentos($db, $id, array $ids)
    {
        return self::replaceLinks($db, self::TBL_ROLE_MED, 'fk_medicamento', $id, $ids);
    }

    /**
     * Reemplaza el conjunto de usuarios asignados a un rol.
     */
    public static function setRoleUsers($db, $id, array $userids)
    {
        $id = (int) $id;
        $p = MAIN_DB_PREFIX;
        $db->query("DELETE FROM ".$p.self::TBL_USER_ROLE." WHERE fk_role = ".$id);
        foreach (array_unique(array_map('intval', $userids)) as $uid) {
            if ($uid > 0) {
                $db->query("INSERT INTO ".$p.self::TBL_USER_ROLE." (fk_user, fk_role) VALUES (".$uid.", ".$id.")");
            }
        }
        return 1;
    }

    /**
     * Helper genérico: reemplaza los enlaces (rol → valores) de una tabla.
     */
    private static function replaceLinks($db, $table, $col, $id, array $ids)
    {
        $id = (int) $id;
        $p = MAIN_DB_PREFIX;
        $db->query("DELETE FROM ".$p.$table." WHERE fk_role = ".$id);
        foreach (array_unique(array_map('intval', $ids)) as $vid) {
            if ($vid > 0) {
                $db->query("INSERT INTO ".$p.$table." (fk_role, ".$col.") VALUES (".$id.", ".$vid.")");
            }
        }
        return 1;
    }

    // -------------------------------------------------------------------------
    // Resolución del alcance efectivo de un usuario
    // -------------------------------------------------------------------------

    /**
     * Devuelve el alcance efectivo del usuario.
     *
     * @param  DoliDB $db
     * @param  User   $user
     * @return array  ['unrestricted' => bool, 'roles' => [['programas'=>[ids],'medicamentos'=>[ids]], ...]]
     */
    public static function getUserScope($db, $user)
    {
        $uid = (int) $user->id;
        if (isset(self::$scopeCache[$uid])) {
            return self::$scopeCache[$uid];
        }

        // Admin → sin restricción
        if (!empty($user->admin)) {
            return self::$scopeCache[$uid] = array('unrestricted' => true, 'roles' => array());
        }

        $p = MAIN_DB_PREFIX;
        $entity = (int) $GLOBALS['conf']->entity;

        // Roles del usuario en esta entidad
        $sql = "SELECT r.rowid, r.all_access"
            ." FROM ".$p.self::TBL_ROLE." r"
            ." INNER JOIN ".$p.self::TBL_USER_ROLE." ur ON ur.fk_role = r.rowid"
            ." WHERE ur.fk_user = ".$uid." AND r.entity = ".$entity;
        $roles = self::fetchAll($db, $sql);

        // Sin roles → ve todo (decisión: compatibilidad)
        if (empty($roles)) {
            return self::$scopeCache[$uid] = array('unrestricted' => true, 'roles' => array());
        }

        $scopeRoles = array();
        foreach ($roles as $r) {
            if (!empty($r['all_access'])) {
                // Un solo rol de acceso total libera toda restricción
                return self::$scopeCache[$uid] = array('unrestricted' => true, 'roles' => array());
            }
            $scopeRoles[] = array(
                'programas'    => self::getRoleProgramaIds($db, $r['rowid']),
                'medicamentos' => self::getRoleMedicamentoIds($db, $r['rowid']),
            );
        }

        return self::$scopeCache[$uid] = array('unrestricted' => false, 'roles' => $scopeRoles);
    }

    // -------------------------------------------------------------------------
    // Utilidades internas
    // -------------------------------------------------------------------------

    private static function fetchAll($db, $sql)
    {
        $out = array();
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $out[] = (array) $obj;
            }
            $db->free($resql);
        }
        return $out;
    }

    private static function fetchIds($db, $sql)
    {
        $out = array();
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $out[] = (int) $obj->id;
            }
            $db->free($resql);
        }
        return $out;
    }
}
