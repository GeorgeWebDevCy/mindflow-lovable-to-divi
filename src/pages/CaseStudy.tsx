import { useParams, Link, Navigate } from "react-router-dom";
import { motion } from "framer-motion";
import { ArrowLeft, ArrowRight, Clock, Quote } from "lucide-react";
import Navbar from "@/components/Navbar";
import Footer from "@/components/Footer";
import { projects } from "@/data/projects";

const fadeUp = {
  initial: { opacity: 0, y: 30 },
  animate: { opacity: 1, y: 0 },
};

const CaseStudy = () => {
  const { slug } = useParams<{ slug: string }>();
  const projectIndex = projects.findIndex((p) => p.slug === slug);
  const project = projects[projectIndex];

  if (!project) return <Navigate to="/portfolio" replace />;

  const nextProject = projects[(projectIndex + 1) % projects.length];

  return (
    <div className="min-h-screen bg-background">
      <Navbar />

      {/* Hero */}
      <section className="relative pt-28 pb-0 lg:pt-36 bg-primary overflow-hidden">
        <div className="container mx-auto px-6 lg:px-8">
          {/* Back link */}
          <motion.div {...fadeUp} transition={{ duration: 0.5 }}>
            <Link
              to="/portfolio"
              className="inline-flex items-center gap-2 text-sm text-primary-foreground/60 hover:text-primary-foreground transition-colors mb-8"
            >
              <ArrowLeft className="w-4 h-4" />
              Back to Portfolio
            </Link>
          </motion.div>

          <div className="grid lg:grid-cols-2 gap-12 items-end">
            <motion.div
              {...fadeUp}
              transition={{ duration: 0.7, delay: 0.1 }}
              className="pb-12 lg:pb-16"
            >
              <span className="inline-block rounded-full bg-accent px-4 py-1.5 text-xs font-semibold text-accent-foreground mb-6">
                {project.category}
              </span>
              <h1 className="font-heading text-3xl sm:text-4xl lg:text-5xl font-bold text-primary-foreground leading-tight mb-4">
                {project.title}
              </h1>
              <p className="text-lg text-primary-foreground/60 mb-6">
                {project.client}
              </p>
              <div className="flex flex-wrap items-center gap-4">
                <div className="inline-flex items-center gap-2 text-sm text-primary-foreground/50">
                  <Clock className="w-4 h-4" />
                  Timeline: {project.timeline}
                </div>
                <div className="flex flex-wrap gap-2">
                  {project.services.map((s) => (
                    <span
                      key={s}
                      className="rounded-md bg-primary-foreground/10 px-3 py-1 text-xs font-medium text-primary-foreground/70"
                    >
                      {s}
                    </span>
                  ))}
                </div>
              </div>
            </motion.div>

            <motion.div
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ duration: 0.7, delay: 0.3 }}
              className="relative"
            >
              <img
                src={project.image}
                alt={project.title}
                className="w-full aspect-[4/3] object-cover rounded-t-2xl lg:rounded-t-3xl shadow-elevated"
              />
            </motion.div>
          </div>
        </div>
      </section>

      {/* Results bar */}
      <section className="bg-card border-b border-border">
        <div className="container mx-auto px-6 lg:px-8">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.6, delay: 0.5 }}
            className="grid grid-cols-2 lg:grid-cols-4 gap-6 py-10 lg:py-12"
          >
            {project.results.map((r, i) => (
              <motion.div
                key={r.label}
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.5, delay: 0.6 + i * 0.1 }}
                className="text-center"
              >
                <p className="font-heading text-3xl lg:text-4xl font-bold text-accent mb-1">
                  {r.value}
                </p>
                <p className="text-sm text-muted-foreground">{r.label}</p>
              </motion.div>
            ))}
          </motion.div>
        </div>
      </section>

      {/* Content */}
      <section className="py-16 lg:py-24">
        <div className="container mx-auto px-6 lg:px-8">
          <div className="max-w-3xl mx-auto space-y-16">
            {/* Overview */}
            <motion.div
              {...fadeUp}
              transition={{ duration: 0.6, delay: 0.2 }}
              viewport={{ once: true }}
              whileInView="animate"
              initial="initial"
            >
              <h2 className="font-heading text-2xl lg:text-3xl font-bold mb-4">
                Project <span className="text-gradient-accent">Overview</span>
              </h2>
              <p className="text-muted-foreground leading-relaxed text-base lg:text-lg">
                {project.overview}
              </p>
            </motion.div>

            {/* Challenge */}
            <motion.div
              {...fadeUp}
              transition={{ duration: 0.6 }}
              viewport={{ once: true }}
              whileInView="animate"
              initial="initial"
            >
              <h2 className="font-heading text-2xl lg:text-3xl font-bold mb-4">
                The <span className="text-gradient-accent">Challenge</span>
              </h2>
              <p className="text-muted-foreground leading-relaxed text-base lg:text-lg">
                {project.challenge}
              </p>
            </motion.div>

            {/* Approach */}
            <motion.div
              {...fadeUp}
              transition={{ duration: 0.6 }}
              viewport={{ once: true }}
              whileInView="animate"
              initial="initial"
            >
              <h2 className="font-heading text-2xl lg:text-3xl font-bold mb-6">
                Our <span className="text-gradient-accent">Approach</span>
              </h2>
              <div className="space-y-4">
                {project.approach.map((step, i) => (
                  <motion.div
                    key={i}
                    initial={{ opacity: 0, x: -20 }}
                    whileInView={{ opacity: 1, x: 0 }}
                    viewport={{ once: true }}
                    transition={{ duration: 0.5, delay: i * 0.1 }}
                    className="flex gap-4 items-start"
                  >
                    <span className="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-accent text-accent-foreground text-sm font-bold">
                      {i + 1}
                    </span>
                    <p className="text-muted-foreground leading-relaxed pt-1">
                      {step}
                    </p>
                  </motion.div>
                ))}
              </div>
            </motion.div>

            {/* Results detail */}
            <motion.div
              {...fadeUp}
              transition={{ duration: 0.6 }}
              viewport={{ once: true }}
              whileInView="animate"
              initial="initial"
            >
              <h2 className="font-heading text-2xl lg:text-3xl font-bold mb-6">
                The <span className="text-gradient-accent">Results</span>
              </h2>
              <div className="grid grid-cols-2 gap-4">
                {project.results.map((r, i) => (
                  <motion.div
                    key={r.label}
                    initial={{ opacity: 0, scale: 0.9 }}
                    whileInView={{ opacity: 1, scale: 1 }}
                    viewport={{ once: true }}
                    transition={{ duration: 0.4, delay: i * 0.1 }}
                    className="bg-card border border-border rounded-2xl p-6 text-center"
                  >
                    <p className="font-heading text-2xl lg:text-3xl font-bold text-accent mb-1">
                      {r.value}
                    </p>
                    <p className="text-sm text-muted-foreground">{r.label}</p>
                  </motion.div>
                ))}
              </div>
            </motion.div>

            {/* Testimonial */}
            {project.testimonial && (
              <motion.div
                {...fadeUp}
                transition={{ duration: 0.6 }}
                viewport={{ once: true }}
                whileInView="animate"
                initial="initial"
                className="relative bg-primary rounded-2xl p-8 lg:p-12"
              >
                <Quote className="w-10 h-10 text-accent/30 mb-4" />
                <blockquote className="font-heading text-xl lg:text-2xl font-medium text-primary-foreground leading-relaxed mb-6">
                  "{project.testimonial.quote}"
                </blockquote>
                <div>
                  <p className="font-semibold text-primary-foreground">
                    {project.testimonial.author}
                  </p>
                  <p className="text-sm text-primary-foreground/60">
                    {project.testimonial.role}
                  </p>
                </div>
              </motion.div>
            )}
          </div>
        </div>
      </section>

      {/* Next project + CTA */}
      <section className="py-16 lg:py-20 bg-card border-t border-border">
        <div className="container mx-auto px-6 lg:px-8 max-w-3xl">
          <div className="flex flex-col sm:flex-row items-center justify-between gap-8">
            {/* Next project */}
            <div>
              <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-2">
                Next Project
              </p>
              <Link
                to={`/case-study/${nextProject.slug}`}
                className="inline-flex items-center gap-2 font-heading text-lg font-semibold hover:text-accent transition-colors"
              >
                {nextProject.title}
                <ArrowRight className="w-4 h-4" />
              </Link>
            </div>

            {/* CTA */}
            <Link
              to="/"
              onClick={() => {
                setTimeout(() => {
                  document
                    .querySelector("#contact")
                    ?.scrollIntoView({ behavior: "smooth" });
                }, 100);
              }}
              className="inline-flex items-center gap-2 rounded-lg bg-accent px-7 py-3.5 text-sm font-semibold text-accent-foreground shadow-accent-glow hover:opacity-90 transition-opacity"
            >
              Start Your Project
              <ArrowRight className="w-4 h-4" />
            </Link>
          </div>
        </div>
      </section>

      <Footer />
    </div>
  );
};

export default CaseStudy;
