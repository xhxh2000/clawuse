/**
 * 属性统计工具函数 - 供多页面共用
 * 
 * 包含：
 * - calcStatsAtLevel: 计算单个角色在指定等级的属性值
 * - calcMaxStatsAtLevel: 计算所有角色在指定等级时的属性最大最小值
 * - calcMinStatsAtLevel: 计算所有角色在指定等级时的属性最小值
 */

/**
 * 计算单个角色在指定等级时的属性值
 * 公式：baseStats + (level - 1) * growthStats
 * 
 * @param {Object} card - 角色对象，包含 baseStats 和 growthStats
 * @param {number} level - 目标等级（1-100）
 * @returns {Object} 该等级时的属性值 {HP, ATK, DEF, SKL, SPD}
 */
function calcStatsAtLevel(card, level) {
    var base = card.baseStats || {};
    var growth = card.growthStats || {};
    var factor = (level > 1) ? (level - 1) : 0;
    
    return {
        HP: (base.HP || 0) + factor * (growth.HP || 0),
        ATK: (base.ATK || 0) + factor * (growth.ATK || 0),
        DEF: (base.DEF || 0) + factor * (growth.DEF || 0),
        SKL: (base.SKL || 0) + factor * (growth.SKL || 0),
        SPD: (base.SPD || 0) + factor * (growth.SPD || 0)
    };
}

/**
 * 计算所有角色在指定等级时的属性最大值和最小值
 * 
 * @param {Array} cards - 角色数组
 * @param {number} level - 目标等级（默认100）
 * @returns {Object} { maxStats: {...}, minStats: {...} }
 */
function calcMaxStatsAtLevel(cards, level) {
    level = level || 100;
    
    var maxStats = { HP: 0, ATK: 0, DEF: 0, SKL: 0, SPD: 0 };
    var minStats = { HP: Infinity, ATK: Infinity, DEF: Infinity, SKL: Infinity, SPD: Infinity };
    
    if (!cards || cards.length === 0) {
        return { maxStats: maxStats, minStats: { HP: 0, ATK: 0, DEF: 0, SKL: 0, SPD: 0 } };
    }
    
    cards.forEach(function(card) {
        var stats = calcStatsAtLevel(card, level);
        
        if (stats.HP > maxStats.HP) maxStats.HP = stats.HP;
        if (stats.HP < minStats.HP) minStats.HP = stats.HP;
        
        if (stats.ATK > maxStats.ATK) maxStats.ATK = stats.ATK;
        if (stats.ATK < minStats.ATK) minStats.ATK = stats.ATK;
        
        if (stats.DEF > maxStats.DEF) maxStats.DEF = stats.DEF;
        if (stats.DEF < minStats.DEF) minStats.DEF = stats.DEF;
        
        if (stats.SKL > maxStats.SKL) maxStats.SKL = stats.SKL;
        if (stats.SKL < minStats.SKL) minStats.SKL = stats.SKL;
        
        if (stats.SPD > maxStats.SPD) maxStats.SPD = stats.SPD;
        if (stats.SPD < minStats.SPD) minStats.SPD = stats.SPD;
    });
    
    // 确保最小值不为无穷大
    if (minStats.HP === Infinity) minStats.HP = 0;
    if (minStats.ATK === Infinity) minStats.ATK = 0;
    if (minStats.DEF === Infinity) minStats.DEF = 0;
    if (minStats.SKL === Infinity) minStats.SKL = 0;
    if (minStats.SPD === Infinity) minStats.SPD = 0;
    
    return {
        maxStats: maxStats,
        minStats: minStats
    };
}

/**
 * 计算属性百分比（用于雷达图/颜色）
 * 公式：(value - min) / (max - min) * 100
 * 
 * @param {number} value - 当前值
 * @param {number} min - 最小值
 * @param {number} max - 最大值
 * @returns {number} 百分比（0-100）
 */
function calcStatPercent(value, min, max) {
    if (max <= min) return 0;
    return Math.round((value - min) / (max - min) * 100);
}

/**
 * 计算所有角色在指定等级时的属性最小值
 * 
 * @param {Array} cards - 角色数组
 * @param {number} level - 目标等级（默认100）
 * @returns {Object} 最小属性值 {HP, ATK, DEF, SKL, SPD}
 */
function calcMinStatsAtLevel(cards, level) {
    level = level || 100;
    
    var minStats = { HP: Infinity, ATK: Infinity, DEF: Infinity, SKL: Infinity, SPD: Infinity };
    
    if (!cards || cards.length === 0) {
        return { HP: 0, ATK: 0, DEF: 0, SKL: 0, SPD: 0 };
    }
    
    cards.forEach(function(card) {
        var stats = calcStatsAtLevel(card, level);
        
        if (stats.HP < minStats.HP) minStats.HP = stats.HP;
        if (stats.ATK < minStats.ATK) minStats.ATK = stats.ATK;
        if (stats.DEF < minStats.DEF) minStats.DEF = stats.DEF;
        if (stats.SKL < minStats.SKL) minStats.SKL = stats.SKL;
        if (stats.SPD < minStats.SPD) minStats.SPD = stats.SPD;
    });
    
    // 确保最小值不为无穷大
    if (minStats.HP === Infinity) minStats.HP = 0;
    if (minStats.ATK === Infinity) minStats.ATK = 0;
    if (minStats.DEF === Infinity) minStats.DEF = 0;
    if (minStats.SKL === Infinity) minStats.SKL = 0;
    if (minStats.SPD === Infinity) minStats.SPD = 0;
    
    return minStats;
}
