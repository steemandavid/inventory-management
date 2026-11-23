// Raspberry Pi 1 Model B+ enclosure that doubles as a stand for the Dymo LabelWriter 450.
//
// Names used throughout (also in README.md):
//   base           bottom part: with the Pi standoffs, port fillers, locating rim and the
//                  screw holes with counterbores. Printed flat.
//   cover          upper part: top panel + back wall + side walls + front, the partitions and
//                  the screw posts. Printed upside down (top panel on the build plate).
//   top panel      flat top of the cover; the Dymo stands on it
//   partitions     inner walls of the cover: Pi compartment vs. cable pocket
//   screw posts    posts in the cover with heat-set inserts; the base is screwed to them
//   Pi standoffs   posts on the base with heat-set inserts; the Pi is screwed to them
//   port openings  openings in the back wall for the USB and RJ45 ports (open at the bottom)
//   power hole     closed hole in the back wall for the micro-USB power plug
//   pocket opening opening in the back wall into the cable pocket
//   port fillers   blocks on the base that close the port openings below the ports
//   locating rim   low rim on the base that fits just inside the cover's walls
//
// The cover is lowered over the base and fixed from below with M2.5 x 5 mm screws into the
// inserts in its screw posts, so the top panel stays flat. The Pi is fixed with the same
// screws into the inserts in the Pi standoffs.
//
// Back wall, left to right as seen from the FRONT (mirrored when you look at the back):
//   [USB] [USB] [RJ45] [power hole] | [pocket opening]
// - Pi components up, ports to the back: the GPIO header is then on the left and the RJ45 and the
//   micro-USB power connector on the right (checked against a photo of the board's underside).
// - RJ45 and USB are flush with the back wall: plug in without opening the enclosure.
// - The micro-USB power plug is pushed through the power hole and plugged into the Pi inside;
//   the cable runs through the power cable channel to the right of the Pi.
// - The cable pocket (right side + front) opens to the back over its full width: push the
//   coiled spare USB cable in from behind.
//
// Render one part:  openscad -D 'part="cover"' -o cover.stl pi_enclosure.scad
// part = "cover" | "base" | "insert_test"   (STL export)
//        "assembly" | "open" | "closed" | "cover_view" | "none"   (previews)

part = "assembly";

/* ---------- measured / to check ---------- */
outer_w   = 127;    // footprint width  (measured from photo of the Dymo 450 underside: ~127 mm)
outer_d   = 186;    // footprint depth  (measured: ~185 mm)
r_front   = 30;     // corner radius at the front (the Dymo's front is strongly rounded)
r_back    = 5;      // corner radius at the back: kept small so the back wall is straight where the ports are

insert_d   = 3.2;   // hole for the M2.5 heat-set insert (3.5 mm OD x 3 mm): ~0.3 under the OD, check with insert_test.stl
insert_len = 3.0;   // insert length
insert_hole_depth = 4.5;   // deeper than the insert: an M2.5 x 5 screw reaches ~3 mm into a screw post
screw_d    = 2.7;   // clearance hole M2.5 in the base (2.9 printed too large: the screw head fell through)
head_d     = 3.4;   // counterbore for the Phillips head (David: 3.4 mm wide, 1 mm deep)
head_h     = 1.0;   // counterbore depth: the head sits flush with the underside of the base

/* ---------- construction ---------- */
wall        = 2.4;  // back wall, side walls and front of the cover
partition_t = 1.6;  // partitions
top_panel_t = 3.0;  // top panel: carries the Dymo
base_t      = 3.0;  // base thickness
inner_h     = 30;   // free height between the base and the top panel
fit         = 0.3;  // clearance between base and cover
rim_h       = 2;    // locating rim height
post_d      = 8.5;  // screw post diameter

/* ---------- Raspberry Pi 1 B+ ---------- */
pi_l = 85; pi_w = 56; pi_t = 1.4;
standoff_h    = 6;          // Pi standoff height = board underside above the base (SD card + solder side)
port_overhang = 2.2;        // USB/RJ45 stick out beyond the board edge
port_recess   = 0.5;        // port fronts this far behind the outer face of the back wall
pi_x0 = 4;                  // board edge with the GPIO header (left); the power edge faces right
pi_y0 = outer_d - port_recess - port_overhang - pi_l;   // SD card end (front)
pcb_z = standoff_h;         // board underside, relative to the top of the base
pi_holes = [[3.5, 3.5], [61.5, 3.5], [3.5, 52.5], [61.5, 52.5]];   // [along, across] on the board

// board coordinates [along 0..85 (0 = SD end, 85 = ports), across 0..56 (0 = power edge)]
// Components up and ports to the back put the power edge on the right.
function pi_pt(p) = [pi_x0 + pi_w - p[1], pi_y0 + p[0]];

// port openings: [centre across the board, opening width, port height above the board]
ports = [[10.25, 17.0, 13.6],   // RJ45
         [29.0,  15.0, 16.0],   // USB pair
         [47.0,  15.0, 16.0]];  // USB pair
port_clear = 0.7;           // above the port top

// power: micro-USB on the power edge, 10.6 mm from the SD end; power hole in the back wall
power_y    = pi_y0 + 10.6;
power_hole = [72.5, 14, 10, 13];   // [x centre, width, height, z centre above the base]

// partitions: Pi compartment (left/back, with the power cable channel) vs. cable pocket (right + front)
partition_x = 88;                // partition right of the power cable channel
partition_y = pi_y0 - 5;         // partition in front of the Pi (SD card sticks out ~2.5 mm)
pocket_opening   = [partition_x + partition_t, outer_w - wall];   // the cable pocket's full width (~35 mm)
pocket_opening_h = inner_h - 2;  // full height minus a 2 mm strip under the top panel

// screw posts: 2 front corners, one on each side wall (opposite each other), one at the back
// between the power hole and the pocket opening (the back corners hold the Pi and the pocket opening)
function corner_post(r) = r - (r - wall - post_d / 2 + 0.3) / sqrt(2);
pf = corner_post(r_front);
post_side = wall + post_d / 2 - 0.3;
mid_y = 83;
screw_posts = [[pf, pf], [outer_w - pf, pf],
               [post_side, mid_y], [outer_w - post_side, mid_y],
               [partition_x + partition_t - post_d / 2 - 0.1, outer_d - post_side]];

$fn = 48;
eps = 0.01;

// Footprint with different corner radii front (y = 0) and back (y = outer_d), shrunk by `inset`.
module footprint(inset = 0) {
    offset(delta = -inset) hull() {
        for (x = [r_front, outer_w - r_front]) translate([x, r_front]) circle(r = r_front);
        for (x = [r_back, outer_w - r_back]) translate([x, outer_d - r_back]) circle(r = r_back);
    }
}
module footprint_solid(h, inset = 0) { linear_extrude(height = h) footprint(inset); }

// port openings and pocket opening in the back wall, as [x0, x1, z0, z1] (z relative to the top of the base)
function port_opening(p) = let(cx = pi_pt([0, p[0]])[0])
    [cx - p[1] / 2, cx + p[1] / 2, -1, pcb_z + pi_t + p[2] + port_clear];
openings_open_bottom = concat([for (p = ports) port_opening(p)],
                              [[pocket_opening[0], pocket_opening[1], -1, pocket_opening_h]]);

/* ===================== cover ===================== */
module cover() {
    h = inner_h + top_panel_t;
    difference() {
        union() {
            // top panel + walls
            difference() {
                footprint_solid(h);
                translate([0, 0, -eps]) footprint_solid(inner_h + eps, wall);
            }
            // partitions, hanging from the top panel; they stop above the locating rim
            intersection() {
                footprint_solid(h, wall / 2);
                union() {
                    translate([0, partition_y, rim_h + 0.3]) cube([partition_x + partition_t, partition_t, h - rim_h - 0.3]);
                    translate([partition_x, partition_y, rim_h + 0.3]) cube([partition_t, outer_d, h - rim_h - 0.3]);
                }
            }
            // screw posts
            for (s = screw_posts) translate([s[0], s[1], 0]) cylinder(d = post_d, h = h - eps);
        }
        // insert holes in the screw posts (from below)
        for (s = screw_posts) translate([s[0], s[1], -eps]) cylinder(d = insert_d, h = insert_hole_depth);
        // port openings and pocket opening: open at the bottom so the cover slides down over the Pi
        for (o = openings_open_bottom) translate([o[0], outer_d - wall - 1, o[2]]) cube([o[1] - o[0], wall + 2, o[3] - o[2]]);
        // power hole: closed, rounded, big enough to pass the micro-USB plug through
        translate([power_hole[0], outer_d - wall / 2, power_hole[3]]) rotate([90, 0, 0])
            linear_extrude(height = wall + 2, center = true) offset(r = 2) offset(delta = -2)
                square([power_hole[1], power_hole[2]], center = true);
        // vent slots in the left side wall along the Pi compartment
        for (y = [pi_y0 + 6 : 6 : outer_d - 16]) translate([-eps, y, 6]) cube([wall + 2 * eps, 2.2, inner_h - 12]);
    }
}

/* ===================== base ===================== */
module base() {
    difference() {
        union() {
            footprint_solid(base_t);
            // locating rim: fits just inside the cover's walls
            translate([0, 0, base_t - eps]) linear_extrude(height = rim_h) difference() {
                footprint(wall + fit);
                footprint(wall + fit + 1.6);
            }
            // Pi standoffs
            for (p = pi_holes) translate(concat(pi_pt(p), [base_t - eps])) cylinder(d = 7, h = standoff_h + eps);
            // port fillers: close the port openings below the ports. The board edge stays 0.3 mm in
            // front of the back wall, so a port filler can go up to just under the ports themselves.
            for (p = ports) let(o = port_opening(p))
                translate([o[0] + fit, outer_d - wall, base_t - eps]) cube([o[1] - o[0] - 2 * fit, wall, pcb_z + pi_t - 0.3]);
        }
        // locating rim: gaps for the screw posts and the openings in the back wall
        for (s = screw_posts) translate([s[0], s[1], base_t]) cylinder(d = post_d + 2 * fit + 1, h = rim_h + 1);
        for (o = openings_open_bottom) translate([o[0] - 0.5, outer_d - wall - 5, base_t + eps]) cube([o[1] - o[0] + 1, 6, rim_h + 1]);
        // screw holes + counterbores from below, under the screw posts
        for (s = screw_posts) translate([s[0], s[1], -eps]) {
            cylinder(d = screw_d, h = base_t + 3);
            cylinder(d = head_d, h = head_h);
        }
        // insert holes in the Pi standoffs (from the top)
        for (p = pi_holes) translate(concat(pi_pt(p), [base_t + standoff_h - insert_hole_depth])) cylinder(d = insert_d, h = insert_hole_depth + eps);
        // vent slots in the base under the Pi
        for (x = [pi_x0 + 12 : 5 : pi_x0 + pi_w - 12]) translate([x, pi_y0 + 16, -eps]) cube([2, pi_l - 36, base_t + 2 * eps]);
    }
}

/* ===================== insert test coupon ===================== */
// holes 3.0 / 3.1 / 3.2 / 3.3 / 3.4 mm, 4.5 mm deep, with the size engraved next to each
module insert_test() {
    sizes = [3.0, 3.1, 3.2, 3.3, 3.4];
    labels = ["3.0", "3.1", "3.2", "3.3", "3.4"];
    difference() {
        cube([len(sizes) * 9 + 3, 13, 7]);
        for (i = [0 : len(sizes) - 1]) {
            translate([6 + i * 9, 8, 7 - insert_hole_depth]) cylinder(d = sizes[i], h = insert_hole_depth + 1);
            translate([6 + i * 9, 1.2, 7 - 0.6]) linear_extrude(1) text(labels[i], size = 2.4, halign = "center");
        }
    }
}

/* ===================== previews ===================== */
module pi_dummy() {
    z = base_t + pcb_z;
    color("green") translate([pi_x0, pi_y0, z]) cube([pi_w, pi_l, pi_t]);
    for (p = ports) let(o = port_opening(p))
        color("silver") translate([o[0] + 0.8, outer_d - port_recess - 21, z + pi_t]) cube([o[1] - o[0] - 1.6, 21, p[2]]);
    // micro-USB power plug sticking out on the right, cable through the power cable channel to the power hole
    color("black") translate([pi_x0 + pi_w, power_y - 4, z + pi_t]) cube([18, 8, 6]);
    color("black") translate([0, 0, z + pi_t + 3]) for (seg = [[[pi_x0 + pi_w + 18, power_y], [84, power_y + 12]],
            [[84, power_y + 12], [80, outer_d - 30]], [[80, outer_d - 30], [power_hole[0], outer_d - 2]]])
        hull() for (q = seg) translate(q) sphere(d = 3.5, $fn = 12);
}

module dymo_ghost() {
    %translate([0, 0, base_t + inner_h + top_panel_t + 0.5]) footprint_solid(120);
}

if (part == "cover") {
    // upside down: the top panel lies on the build plate
    translate([0, outer_d, inner_h + top_panel_t]) rotate([180, 0, 0]) cover();
} else if (part == "base") {
    base();
} else if (part == "insert_test") {
    insert_test();
} else if (part == "open") {
    base();
    pi_dummy();
} else if (part == "none") {
    // nothing: for files that include this one
} else if (part == "closed") {
    base();
    pi_dummy();
    color("SteelBlue") translate([0, 0, base_t]) cover();
} else if (part == "cover_view") {
    translate([0, 0, base_t]) cover();
} else {
    base();
    pi_dummy();
    color([0.9, 0.9, 0.95, 0.35]) translate([0, 0, base_t]) cover();
    dymo_ghost();
}
